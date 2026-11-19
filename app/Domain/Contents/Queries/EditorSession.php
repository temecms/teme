<?php

/**
 * What the editor opens on for one person: their own autosave when they have
 * one, the working revision otherwise, never another person's unsaved work.
 * Title, document, fields and terms always come from the same one of the
 * two. It also says who else is in the article, what this person may do,
 * the media the document's cards show, and where the article stands in
 * scheduling and review.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\References;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\Queries\MediaCredits;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class EditorSession
{
    /**
     * @param  array<mixed>  $document
     * @param  list<array{id: int, name: string}>  $otherEditors
     * @param  array<string, mixed>  $fields
     * @param  list<array{id: string, name: string, taxonomy: string}>  $terms
     * @param  list<array{key: string, singular: string, plural: string, segment: string, hierarchical: bool, canCreate: bool, terms: list<array{id: string, name: string, parentId: string|null}>|null}>  $taxonomies
     * @param  array{edit: bool, publish: bool, delete: bool, manageTerms: bool, translate: bool}  $can
     * @param  array{id: int, name: string}|null  $author
     * @param  array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, credit: string|null}>  $media
     * @param  array{runAt: string, heldReason: string|null, failedReason: string|null}|null  $schedule
     * @param  array{id: string, state: 'pending'|'returned', submittedBy: string|null, note: string|null, revisionId: string}|null  $review
     * @param  array<string, array{title: string|null, state: 'published'|'unpublished'|'trashed'|'missing'}>  $linked
     * @param  list<array{slug: string, label: string}>  $templates
     * @param  array<string, array{title: string, type: string, status: string}>  $entries
     * @param  list<array{tag: string, name: string, shown: bool}>  $languages
     * @param  list<array{language: string, status: string, visibility: string, outOfDate: bool, sourceLanguage: string|null, sourceChangedAt: string|null, current: bool, id: string|null, title: string|null, editUrl: string|null, workingRevisionId: string|null}>  $translations
     */
    public function __construct(
        public string $id,
        public array $document,
        public bool $fromAutosave,
        public ?string $savedAt,
        public ?string $workingRevisionId,
        public array $otherEditors,
        public string $title,
        public string $type,
        public string $status,
        public ?string $publishedAt,
        /** Whether a live item has changes readers don't see yet: a revision saved after the published one, or this person's own autosave. */
        public bool $unpublishedChanges,
        public array $fields,
        public array $terms,
        public array $taxonomies,
        public array $can,
        public array $media,
        public string $slug,
        public string $addressPattern,
        /** The item's live address, for View on site, or null when it has none. */
        public ?string $url,
        /** The active theme's page templates for the item's type, in the manifest's order (I-443). */
        public array $templates,
        public ?array $author,
        public int $revisionCount,
        public ?array $schedule,
        public ?array $review,
        public array $linked,
        /** Who may read it (I-1700). */
        public string $visibility,
        /** The password readers give, for whoever may set it; null otherwise. */
        public ?string $readerPassword,
        /** Whether a protected item's password can't be read on this server. */
        public bool $passwordUnreadable,
        /** The items the fields point at by ID, for their controls' first paint; apart from linked, which the body's checks read. */
        public array $entries = [],
        /** The item's language, by its tag (I-3360). */
        public string $language = '',
        /** The site's languages, the default first; none on a site with one. */
        public array $languages = [],
        /** Its group's live versions, itself included, never the group's ID; one the person may not open by its language and state alone. */
        public array $translations = [],
    ) {}

    /**
     * The session for this person, or null when the item is missing or in
     * the trash.
     */
    public static function for(string $contentId, string $userId): ?self
    {
        $content = Content::query()->find($contentId);
        $viewer = User::query()->find($userId);

        if ($content === null || $viewer === null) {
            return null;
        }

        $autosave = ContentAutosave::query()->where('content_id', $content->id)->where('user_id', $viewer->id)->first();
        $revision = $content->working_revision_id === null ? null : ContentRevision::query()->find($content->working_revision_id);

        $title = $autosave !== null ? $autosave->title : (string) ($revision->title ?? $content->title);
        $document = $autosave !== null ? $autosave->document : (array) ($revision->document ?? ['type' => 'doc', 'content' => []]);
        $fields = $autosave !== null ? $autosave->fields : (array) ($revision->fields ?? []);
        $termIds = $autosave !== null ? $autosave->terms : (array) ($revision->terms ?? []);

        $gate = Gate::forUser($viewer);
        $languages = app(SiteLanguages::class);
        // A type that isn't translated is always in the default language: no Language row, no Translate (D25).
        $multilingual = $languages->isMultilingual() && $languages->translates((string) $content->type);

        return new self(
            id: $content->id,
            document: $document,
            fromAutosave: $autosave !== null,
            savedAt: self::iso($autosave?->saved_at),
            workingRevisionId: $content->working_revision_id,
            otherEditors: EditorPresence::othersIn($content->id, (string) $viewer->id),
            title: $title,
            type: $content->type,
            status: $content->status,
            publishedAt: self::iso($content->getAttribute('published_at')),
            unpublishedChanges: $content->status === 'published'
                && ($content->working_revision_id !== $content->published_revision_id || $autosave !== null),
            fields: $fields,
            terms: self::terms($termIds),
            // A hierarchical taxonomy is drawn as a tree of every term; a flat
            // one searches as the person types, so it carries none.
            taxonomies: array_map(fn (Taxonomy $taxonomy): array => [
                'key' => $taxonomy->key,
                'singular' => $taxonomy->singular,
                'plural' => $taxonomy->plural,
                'segment' => $taxonomy->segment,
                'hierarchical' => $taxonomy->hierarchical,
                // As CreateTerm and its route decide, so the picker never offers what would be refused.
                'canCreate' => $gate->allows('terms.manage') && $gate->allows($taxonomy->ability),
                'terms' => $taxonomy->hierarchical ? app(TermPicker::class)->tree($taxonomy->key) : null,
            ], app(TaxonomyRegistry::class)->forType($content->type)),
            can: [
                'edit' => $gate->allows('update', $content),
                'publish' => $gate->allows('publish', $content),
                'delete' => $gate->allows('delete', $content),
                'manageTerms' => $gate->allows('terms.manage'),
                'translate' => $multilingual && TranslateContent::allows($viewer, $content),
            ],
            media: self::media($document, $fields, $content->type),
            slug: (string) $content->slug,
            addressPattern: self::addressPattern($content, $termIds, $fields[BuiltInFields::PRIMARY_CATEGORY] ?? null),
            url: Addresses::urlFor($content),
            templates: self::templates($content->type),
            author: self::author($content->author_id),
            revisionCount: ContentRevision::query()->where('content_id', $content->id)->count(),
            schedule: self::schedule($content->id),
            review: self::review($content->id),
            // For the check on links to unpublished articles, right on the
            // first paint; links added later are asked about as they come.
            linked: LinkedContent::states(References::contentIds($document)),
            visibility: $content->visibility->value,
            readerPassword: $gate->allows('publish', $content) ? ReaderPasswords::open($content) : null,
            passwordUnreadable: $content->visibility === Visibility::Protected && ReaderPasswords::open($content) === null,
            entries: self::entries($fields, $content->type),
            language: $languages->of($content->language),
            // compact(): the key is a language's tag, never the taxonomy (NoTypeLiteralsTest).
            languages: $multilingual ? array_map(fn (string $tag): array => compact('tag') + [
                'name' => $languages->label($tag),
                'shown' => $languages->isShown($languages->stored($tag)),
            ], $languages->all()) : [],
            translations: $multilingual ? self::translations($content, $viewer) : [],
        );
    }

    /**
     * The item's group's versions with their state (D9), each named, with
     * its editor's address, only to someone who may open it (I-3360).
     *
     * @return list<array{language: string, status: string, visibility: string, outOfDate: bool, sourceLanguage: string|null, sourceChangedAt: string|null, current: bool, id: string|null, title: string|null, editUrl: string|null, workingRevisionId: string|null}>
     */
    private static function translations(Content $content, User $viewer): array
    {
        $versions = app(TranslationStatus::class)->forItem($content);

        if ($versions === []) {
            return [];
        }

        $gate = Gate::forUser($viewer);
        $rows = Content::query()->whereIn('id', array_column($versions, 'id'))->get()->keyBy('id');

        return array_map(function (array $version) use ($rows, $gate, $content): array {
            $row = $rows->get($version['id']);
            $opens = $row !== null && $gate->allows('update', $row);

            return [
                'language' => $version['language'],
                'status' => $version['status'],
                'visibility' => $version['visibility'],
                'outOfDate' => $version['outOfDate'],
                'sourceLanguage' => $version['sourceLanguage'],
                'sourceChangedAt' => $version['sourceChangedAt'],
                'current' => $version['id'] === (string) $content->id,
                'id' => $opens ? $version['id'] : null,
                'title' => $opens ? $version['title'] : null,
                'editUrl' => $opens ? route('content.edit', ['content' => $version['id']], false) : null,
                // What Mark up to date is based on, so a version changed meanwhile is refused as stale.
                'workingRevisionId' => $opens ? $row->working_revision_id : null,
            ];
        }, $versions);
    }

    /**
     * The title, type and status of every item the type's fields point at,
     * in one query; an item gone from the table is left out, and its control
     * says so.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, array{title: string, type: string, status: string}>
     */
    private static function entries(array $fields, string $type): array
    {
        $ids = [];

        foreach (app(FieldRegistry::class)->forType($type) as $field) {
            foreach ($field->type->references($field, $fields[$field->key] ?? null) as $reference) {
                if ($reference->kind === 'content') {
                    $ids[] = $reference->id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $entries = [];

        foreach (DB::table('contents')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'title', 'type', 'status', 'deleted_at']) as $row) {
            $entries[(string) $row->id] = [
                'title' => (string) $row->title,
                'type' => (string) $row->type,
                'status' => $row->deleted_at === null ? (string) $row->status : 'trashed',
            ];
        }

        return $entries;
    }

    /**
     * The templates the active theme offers for this type, as a list, so
     * JSON keeps the manifest's order.
     *
     * @return list<array{slug: string, label: string}>
     */
    private static function templates(string $type): array
    {
        $templates = [];

        foreach (TemplateChoice::declared($type) as $slug => $label) {
            $templates[] = ['slug' => (string) $slug, 'label' => $label];
        }

        return $templates;
    }

    /**
     * The article's pending schedule, held or not; or else its latest
     * schedule when that one failed; or null. The time is ISO 8601 in UTC.
     *
     * @return array{runAt: string, heldReason: string|null, failedReason: string|null}|null
     */
    private static function schedule(string $contentId): ?array
    {
        $latest = DB::table('scheduled_publications')
            ->where('content_id', $contentId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first(['run_at', 'held_reason', 'completed_at', 'failed_at', 'cancelled_at', 'failure_reason']);

        if ($latest === null || $latest->completed_at !== null || $latest->cancelled_at !== null) {
            return null;
        }

        return [
            'runAt' => Carbon::parse($latest->run_at)->utc()->toIso8601String(),
            'heldReason' => $latest->failed_at === null ? $latest->held_reason : null,
            'failedReason' => $latest->failed_at === null ? null : (string) $latest->failure_reason,
        ];
    }

    /**
     * The article's latest review, when it is pending, or was returned and
     * has not been submitted again; null otherwise. Who submitted it is their
     * name.
     *
     * @return array{id: string, state: 'pending'|'returned', submittedBy: string|null, note: string|null, revisionId: string}|null
     */
    private static function review(string $contentId): ?array
    {
        $latest = DB::table('content_reviews')
            ->leftJoin('users', 'users.id', '=', 'content_reviews.submitted_by')
            ->where('content_reviews.content_id', $contentId)
            ->orderByDesc('content_reviews.submitted_at')->orderByDesc('content_reviews.id')
            ->first(['content_reviews.id', 'content_reviews.revision_id', 'content_reviews.decided_at', 'content_reviews.decision', 'content_reviews.note', 'users.name']);

        if ($latest === null || ($latest->decided_at !== null && $latest->decision !== 'returned')) {
            return null;
        }

        return [
            'id' => (string) $latest->id,
            'state' => $latest->decided_at === null ? 'pending' : 'returned',
            'submittedBy' => $latest->name === null ? null : (string) $latest->name,
            'note' => $latest->note === null ? null : (string) $latest->note,
            'revisionId' => (string) $latest->revision_id,
        ];
    }

    /**
     * The media the editor shows: what the document's cards name and what
     * each of the type's fields points at, in one lookup, each image with
     * its credit.
     *
     * @param  array<mixed>  $document
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, credit: string|null}>
     */
    private static function media(array $document, array $fields, string $type): array
    {
        $ids = [];

        foreach (References::of($document) as $reference) {
            if ($reference->kind === 'media') {
                $ids[] = $reference->id;
            }
        }

        foreach (app(FieldRegistry::class)->forType($type) as $field) {
            foreach ($field->type->references($field, $fields[$field->key] ?? null) as $reference) {
                if ($reference->kind === 'media') {
                    $ids[] = $reference->id;
                }
            }
        }

        return app(MediaCredits::class)->onto(app(CardMedia::class)->forIds($ids));
    }

    /**
     * The item's address as the settings shape it, every token but `{slug}`
     * filled for this item (its date on the site's clock, or today's, its
     * category and author); the rail puts the slug in (rule 9). A type with
     * no public address keeps its declared pattern.
     *
     * @param  list<string>  $termIds
     * @param  mixed  $primaryCategory  the primary category field's value, as the editor holds it (I-869)
     */
    private static function addressPattern(Content $content, array $termIds, mixed $primaryCategory): string
    {
        $address = Addresses::wouldHave($content, '{slug}', $termIds, null, $content->getAttribute('published_at'), $primaryCategory);

        if ($address !== null) {
            return $address === '' ? '/' : '/'.$address.(Addresses::trailingSlash($content->type) ? '/' : '');
        }

        $types = app(ContentTypeRegistry::class);

        if (! $types->has($content->type)) {
            return '/{slug}/';
        }

        $definition = $types->get($content->type);

        return $definition->pattern ?? '/'.$definition->segment.'/{slug}/';
    }

    /**
     * Who wrote the article, or null when that account has gone.
     *
     * @return array{id: int, name: string}|null
     */
    private static function author(mixed $authorId): ?array
    {
        $name = $authorId === null ? null : DB::table('users')->where('id', $authorId)->value('name');

        return $name === null ? null : ['id' => (int) $authorId, 'name' => (string) $name];
    }

    /**
     * A date as ISO 8601, or null.
     */
    private static function iso(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->toIso8601String() : null;
    }

    /**
     * The chosen terms that still exist, in the order they were chosen.
     *
     * @param  array<mixed>  $ids
     *
     * @return list<array{id: string, name: string, taxonomy: string}>
     */
    private static function terms(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('terms')->whereIn('id', $ids)->get(['id', 'name', 'taxonomy'])->keyBy('id');
        $terms = [];

        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $terms[] = ['id' => $id, 'name' => (string) $rows[$id]->name, 'taxonomy' => (string) $rows[$id]->taxonomy];
            }
        }

        return $terms;
    }
}
