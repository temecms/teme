<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\PlainText;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Contents\AuthorSort;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Slugs;
use App\Domain\Fields\FieldValidator;
use App\Domain\Hooks\Payloads;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Languages\LanguageChoice;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Operations\OperationInput;

/**
 * Creates a new item of any listed content type as a draft, with its first
 * revision and a unique slug. Its title may be empty, as a new draft's is
 * until written (I-425). Anyone holding the type's create ability can do it,
 * and nothing is published. It is written in a site language, the default
 * unless named (I-3360). A type with one item only gets no second in a
 * language. A type
 * that isn't listed, such as a region, is made by its own Operation, which
 * shares start().
 */
final class CreateDraft extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var CreateDraftInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('create', [Content::class, $input->type])) {
            throw new AuthorizationException('Not permitted to create content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateDraftInput $input */
        LanguageChoice::check($input->language);
        $language = $input->language === null ? null : app(SiteLanguages::class)->stored($input->language);

        if ($language !== null) {
            TranslatedTypes::refuseType($input->type);
        }

        TermRules::refuseOtherLanguage($input->terms, $language);

        Validator::make(
            [
                'type' => $input->type,
                'title' => trim($input->title),
                'document' => $input->document,
                'terms' => $input->terms,
            ],
            [
                'type' => ['required', 'string', Rule::in(app(ContentTypeRegistry::class)->keys())],
                'title' => ['string', 'max:512'],
                'document' => ['required', 'array'],
                'terms' => ['array', 'max:50'],
                'terms.*' => ['string', 'distinct', TermRules::usableBy($input->type, $language)],
            ],
        )->validate();

        // A type that isn't listed has its own screen and its own Operation,
        // such as a region's CreateRegion; it is never started as a new draft.
        $type = app(ContentTypeRegistry::class)->get($input->type);

        if (! $type->listed) {
            throw ValidationException::withMessages(['type' => __(':plural are made on their own screen, not as new drafts.', ['plural' => $type->plural])]);
        }

        app(FieldValidator::class)->validate($input->type, $input->fields);
        // A new item's template is a new choice (I-443).
        TemplateChoice::check($input->type, $input->fields, []);
        app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($input->document));
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var CreateDraftInput $input */
        // The languages' lock first, then the types' (I-3360).
        $language = LanguageChoice::take($input->language);

        if ($language !== null) {
            TranslatedTypes::refuseType($input->type, fromRow: true);
        }

        self::refuseSecond($input->type, $language);

        $title = trim($input->title);

        return self::start(
            $input->type,
            Slugs::unique($input->type, Slugs::fromTitle($title), $language),
            $title,
            $input->document,
            app(FieldValidator::class)->validate($input->type, $input->fields),
            $input->terms,
            (string) $input->actorId(),
            $language,
        );
    }

    /**
     * Whether the type holds one item only (I-415), as it stands now. For a
     * stored type, under the types' lock taken shared, so an UpdateType
     * holding it exclusive has committed, and read from its row: another
     * process's change reaches this registry only after its commit and the
     * cache's copy. A single type's drafts then queue on a lock of their own.
     */
    private static function isSingle(ContentType $type): bool
    {
        if (! $type->stored) {
            return $type->single;
        }

        DB::select("SELECT pg_advisory_xact_lock_shared(hashtext('teme.types'))");

        $row = DB::table(StoredTypes::TABLE)
            ->where('key', $type->key)
            ->selectRaw("COALESCE((definition ->> 'single')::boolean, false) AS single")
            ->first();

        $single = $row !== null && (bool) $row->single;

        if ($single) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.single.' || ?))", [$type->key]);
        }

        return $single;
    }

    /**
     * Refuses an item of a type with one item only (I-415) when the type
     * has one in this language (I-3360), in the list or in the trash; the
     * live one is named first. Call it in perform(), after the languages'
     * lock: a draft, a translation or a move into the language asks it.
     *
     * @param  ?string  $language  as a row stores it, null for the default
     *
     * @throws ValidationException
     */
    public static function refuseSecond(string $type, ?string $language, string $key = 'type'): void
    {
        $declared = app(ContentTypeRegistry::class)->get($type);

        if (self::isSingle($declared)) {
            self::refuseSecondItem($type, $declared->singular, $language, $key);
        }
    }

    /**
     * Refuses the second item, in words, when there is a first.
     *
     * @throws ValidationException
     */
    private static function refuseSecondItem(string $type, string $singular, ?string $language, string $key): void
    {
        // DB::table() sees soft-deleted rows: a trashed item is still the one.
        $item = DB::table('contents')->where('type', $type)
            ->where(fn (Builder $query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->orderByRaw('deleted_at IS NOT NULL')->first(['deleted_at']);

        if ($item === null) {
            return;
        }

        throw ValidationException::withMessages([$key => $item->deleted_at === null
            ? __('There can be only one :singular, and it already exists.', ['singular' => $singular])
            : __('There can be only one :singular, and it’s in the trash: restore it to change it.', ['singular' => $singular])]);
    }

    /**
     * A new draft of this type with this slug, open or closed for comments as
     * its type and the Discussion settings say (I-274), and its first
     * revision, with the counts and projections a new item needs. CreateDraft
     * makes the slug from the title; CreateRegion gives its region's fixed
     * one. Call it inside an Operation's perform(), in its transaction, never
     * on its own.
     *
     * @param  array<mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms
     * @param  string|null  $language  as a row stores it: null for the default language
     * @param  string|null  $group  its translation group, for a translation (I-3360)
     * @param  string|null  $sourceRevisionId  the revision a translation was copied from, kept on its first revision
     */
    public static function start(string $type, string $slug, string $title, array $document, array $fields, array $terms, string $actorId, ?string $language = null, ?string $group = null, ?string $sourceRevisionId = null): Content
    {
        $content = Content::create([
            'type' => $type,
            'language' => $language,
            'translation_group' => $group,
            'slug' => $slug,
            'status' => 'draft',
            'comment_status' => DiscussionSettings::current()->forNewItem($type),
            'title' => $title,
            'author_id' => $actorId,
            'author_sort' => AuthorSort::of(User::query()->whereKey($actorId)->value('name')),
            'fields' => $fields,
        ]);

        // The normalised document is what is stored.
        $document = DocumentNormaliser::normalise($document);

        $revision = ContentRevision::create([
            'content_id' => $content->id,
            'document' => $document,
            'plain_text' => PlainText::of($document),
            'word_count' => PlainText::words($document),
            'fields' => $fields,
            'title' => $title,
            'author_id' => $actorId,
            'origin' => 'human',
            'terms' => array_values(array_unique($terms)),
            'source_revision_id' => $sourceRevisionId,
        ]);

        $content->update(['working_revision_id' => $revision->id]);

        ContentCounts::apply(null, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content, $actorId);
        LinkProjection::sync($content);
        SearchText::sync($content);

        $content->refresh();
        $revisionId = (string) $revision->id;
        app(PendingNotifications::class)->add(ContentSaved::class, fn (): ContentSaved => new ContentSaved(Payloads::item($content), $revisionId, true, $actorId));

        return $content;
    }
}
