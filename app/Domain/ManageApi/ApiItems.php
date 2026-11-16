<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\ManageApi\Queries\CreditablePeople;
use App\Domain\Media\Models\Media;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POST /api/manage/v1/items, step by step (docs/proposals/management-api.md,
 * P3): the draft through CreateDraft as the key's person, recorded as
 * written through the key; then its slug and author through
 * UpdateContentDetails; then PublishRevision or SchedulePublication when
 * asked. What the request names is checked before anything is written; a
 * later step's refusal keeps the draft and is answered with it.
 */
final readonly class ApiItems
{
    /** The statuses a new item may be asked for. */
    public const array STATUSES = ['draft', 'published', 'scheduled'];

    /**
     * Whether a new item asked for with this status needs publish_at.
     */
    public static function needsDate(string $status): bool
    {
        return $status === 'scheduled';
    }

    public function __construct(
        private ContentTypeRegistry $types,
        private TaxonomyRegistry $taxonomies,
        private FieldRegistry $fields,
        private CreditablePeople $people,
    ) {}

    /**
     * Creates the item and answers it: 201 when every step was done, 202
     * with the problem when the draft was kept but a later step refused.
     *
     * @param  array<string, mixed>|null  $document  the body as a Tiptap document
     *
     * @return array{status: int, data: array<string, mixed>}
     */
    public function create(ApiCaller $caller, ApiItemRequest $request, ?array $document): array
    {
        if ($request->status !== 'draft' && ! $caller->may(ApiAbility::ContentPublish)) {
            throw ManageApiProblem::missingAbility(ApiAbility::ContentPublish);
        }

        $fields = $this->fieldsFor($request);
        $terms = $this->terms($request->terms);

        if ($request->authorId !== null && ! $this->people->has($request->authorId)) {
            throw ManageApiProblem::invalid(['author' => __('author must be the ID of a person from /people.')]);
        }

        try {
            $content = app(CreateDraft::class)->execute(new CreateDraftInput(
                type: $request->type,
                title: $request->title,
                document: $document ?? [],
                fields: $fields,
                actorId: $caller->personId,
                terms: $terms,
                via: $caller->via(),
            ));
        } catch (ValidationException $refusal) {
            throw ManageApiProblem::invalid($this->namedAsSent($request->type, $refusal));
        } catch (Throwable $refusal) {
            throw ManageApiProblem::fromRefusal($refusal);
        }

        try {
            if ($request->slug !== null || $request->authorId !== null) {
                app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
                    contentId: (string) $content->id,
                    expectedRevisionId: (string) $content->working_revision_id,
                    actorId: $caller->personId,
                    slug: $request->slug,
                    authorId: $request->authorId,
                ));
                $content = Content::query()->findOrFail($content->id);
            }

            if ($request->status === 'published') {
                $content = app(PublishRevision::class)->execute(new PublishRevisionInput(
                    contentId: (string) $content->id,
                    revisionId: (string) $content->working_revision_id,
                    actorId: $caller->personId,
                ));
            }

            if ($request->status === 'scheduled') {
                app(SchedulePublication::class)->execute(new SchedulePublicationInput(
                    contentId: (string) $content->id,
                    revisionId: (string) $content->working_revision_id,
                    action: 'publish',
                    runAt: Date::parse((string) $request->publishAt)->toIso8601String(),
                    actorId: $caller->personId,
                ));
            }
        } catch (Throwable $refusal) {
            $problem = ManageApiProblem::fromRefusal($refusal);

            if (! $problem instanceof ManageApiProblem) {
                throw $refusal;
            }

            $kept = self::shape(Content::query()->findOrFail($content->id), 'draft');
            $refused = $problem->body('');
            $kept['problem'] = [
                'status' => $refused['status'],
                'code' => $refused['code'],
                'detail' => ManageApiWords::keptAsDraft().' '.$refused['detail'],
                ...(isset($refused['errors']) ? ['errors' => $refused['errors']] : []),
            ];

            return ['status' => 202, 'data' => $kept];
        }

        $content = Content::query()->findOrFail($content->id);

        return ['status' => 201, 'data' => self::shape($content, $request->status === 'scheduled' ? 'scheduled' : (string) $content->status)];
    }

    /**
     * The item as the API answers it: its ID, type, status, slug, title,
     * and its public address while it is published.
     *
     * @return array{id: string, type: string, status: string, slug: string, title: string, url: string|null}
     */
    public static function shape(Content $content, string $status): array
    {
        return [
            'id' => (string) $content->id,
            'type' => (string) $content->type,
            'status' => $status,
            'slug' => (string) $content->slug,
            'title' => (string) $content->title,
            'url' => $content->status === 'published' ? Addresses::urlFor($content) : null,
        ];
    }

    /**
     * Field values keyed as CreateDraft stores them, from the handles sent,
     * with the featured media folded into its built-in field.
     *
     * @return array<string, mixed>
     */
    private function fieldsFor(ApiItemRequest $request): array
    {
        if (! $this->types->has($request->type)) {
            throw ManageApiProblem::invalid(['type' => __('type must be a content type of this site: :types.', ['types' => implode(', ', $this->types->keys())])]);
        }

        $keys = [];

        foreach ($this->fields->forType($request->type) as $field) {
            $keys[$field->handle] = $field->key;
        }

        $fields = [];
        $refused = [];

        foreach ($request->fields as $handle => $value) {
            if (! isset($keys[$handle])) {
                $refused["fields.{$handle}"] = __('A :type has no field :handle.', ['type' => $request->type, 'handle' => $handle]);

                continue;
            }

            $fields[$keys[$handle]] = $value;
        }

        if ($request->featuredMedia !== null) {
            if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $request->featuredMedia) !== 1 || ! Media::query()->whereKey($request->featuredMedia)->exists()) {
                $refused['featured_media'] = __('featured_media must be the ID of a media item.');
            } else {
                $fields[BuiltInFields::FEATURED_IMAGE] = ['id' => $request->featuredMedia, 'alt' => null, 'decorative' => false];
            }
        }

        if ($refused !== []) {
            throw ManageApiProblem::invalid($refused);
        }

        return $fields;
    }

    /**
     * The term IDs sent, each checked to be a term of the taxonomy it was sent under.
     *
     * @param  array<string, list<string>>  $byTaxonomy
     *
     * @return list<string>
     */
    private function terms(array $byTaxonomy): array
    {
        $ids = [];
        $refused = [];

        foreach ($byTaxonomy as $taxonomy => $termIds) {
            if (! $this->taxonomies->has($taxonomy)) {
                $refused["terms.{$taxonomy}"] = ManageApiWords::noTaxonomy($taxonomy);

                continue;
            }

            $found = Term::query()->where('taxonomy', $taxonomy)->whereIn('id', $termIds)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

            if (count(array_unique($termIds)) !== count($found)) {
                $refused["terms.{$taxonomy}"] = __('Each ID under terms.:taxonomy must be a :taxonomy term, from /taxonomies/:taxonomy/terms.', ['taxonomy' => $taxonomy]);

                continue;
            }

            array_push($ids, ...array_values(array_unique($termIds)));
        }

        if ($refused !== []) {
            throw ManageApiProblem::invalid($refused);
        }

        return $ids;
    }

    /**
     * CreateDraft's refusal, each field named as the request sent it: the
     * document as body, a field by its handle.
     *
     * @return array<string, string>
     */
    private function namedAsSent(string $type, ValidationException $refusal): array
    {
        $handles = [];

        foreach ($this->fields->forType($type) as $field) {
            $handles[$field->key] = $field->handle;
        }

        $errors = [];

        foreach ($refusal->errors() as $key => $messages) {
            $key = (string) $key;
            $name = match (true) {
                str_starts_with($key, 'document') => 'body',
                str_starts_with($key, 'fields.') => 'fields.'.($handles[substr($key, 7)] ?? substr($key, 7)),
                str_starts_with($key, 'terms') => 'terms',
                default => $key,
            };
            $errors[$name] ??= (string) ($messages[0] ?? '');
        }

        return $errors;
    }
}
