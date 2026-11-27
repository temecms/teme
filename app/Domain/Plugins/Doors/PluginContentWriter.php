<?php

/**
 * A plugin's content.write door: creates, changes, publishes, unpublishes and schedules
 * items, sets their slugs and credits and links language versions, through
 * Teme's Operations, always on behalf of a named person
 * (docs/proposals/plugin-content-write.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Hooks\Payloads;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\People\Operations\SetBylines;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Media\MediaRef;
use Throwable;

final readonly class PluginContentWriter implements ContentWriter
{
    public function __construct(private string $code) {}

    public function create(NewItem $item, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);

        $credits = self::creditKeys($item->credits);

        try {
            $content = DB::transaction(function () use ($item, $actingPersonId, $credits) {
                $content = app(CreateDraft::class)->execute(new CreateDraftInput(
                    type: $item->type,
                    title: $item->title,
                    document: $item->body,
                    fields: $this->fields($item->type, $item->fields, $item->featuredImage),
                    actorId: $actingPersonId,
                    terms: $this->terms($item->terms, $actingPersonId),
                    language: $item->language,
                    via: $this->code,
                ));

                $this->details((string) $content->id, $item->slug, $item->credits === [] ? null : $credits, $actingPersonId);

                if ($item->translationOf !== null) {
                    $this->link([$item->translationOf, (string) $content->id], $actingPersonId);
                }

                return $content->refresh();
            });
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item($content);
    }

    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);
        $content = Content::query()->find($item->id);

        if ($content === null) {
            throw new WriteRefused(__('That item does not exist.'));
        }

        /** @var ContentRevision|null $revision */
        $revision = ContentRevision::query()->find($content->working_revision_id);

        if ($revision === null) {
            throw new WriteRefused(__('That item has no draft to change.'));
        }

        $credits = $changes->credits === null ? null : self::creditKeys($changes->credits);
        $title = $changes->title ?? (string) $revision->title;
        $body = $changes->body ?? (array) $revision->document;
        $currentFields = (array) $revision->fields;
        $fields = $changes->fields === null && $changes->featuredImage === null
            ? $currentFields
            : $this->fields(
                (string) $content->type,
                $changes->fields ?? $this->fieldsByHandle((string) $content->type, $currentFields),
                $changes->featuredImage,
                $currentFields,
            );
        try {
            $content = DB::transaction(function () use ($content, $revision, $changes, $title, $body, $fields, $actingPersonId, $credits) {
                $terms = $changes->terms === null
                    ? array_map('strval', (array) $revision->terms)
                    : $this->terms($changes->terms, $actingPersonId);

                $saved = app(SaveDraft::class)->execute(new SaveDraftInput(
                    contentId: (string) $content->id,
                    title: $title,
                    document: $body,
                    fields: $fields,
                    expectedRevisionId: (string) $content->working_revision_id,
                    actorId: $actingPersonId,
                    terms: $terms,
                    via: $this->code,
                ));

                $this->details((string) $saved->id, $changes->slug, $credits, $actingPersonId);

                return $saved->refresh();
            });
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item($content);
    }

    public function linkTranslations(array $itemIds, string $actingPersonId): void
    {
        $this->person($actingPersonId);

        try {
            $this->link($itemIds, $actingPersonId);
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }
    }

    public function publish(ItemRef $item, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);
        $content = Content::query()->find($item->id);

        if ($content === null || $content->working_revision_id === null) {
            throw new WriteRefused(__('That item does not exist.'));
        }

        try {
            $content = app(PublishRevision::class)->execute(new PublishRevisionInput(
                contentId: (string) $content->id,
                revisionId: (string) $content->working_revision_id,
                actorId: $actingPersonId,
                via: $this->code,
            ));
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item($content);
    }

    public function unpublish(ItemRef $item, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);

        if (! Content::query()->whereKey($item->id)->exists()) {
            throw new WriteRefused(__('That item does not exist.'));
        }

        try {
            app(UnpublishContent::class)->execute(new ContentActionInput($item->id, $actingPersonId));
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item(Content::query()->findOrFail($item->id));
    }

    public function schedule(ItemRef $item, DateTimeImmutable $at, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);
        $content = Content::query()->find($item->id);

        if ($content === null || $content->working_revision_id === null) {
            throw new WriteRefused(__('That item does not exist.'));
        }

        try {
            app(SchedulePublication::class)->execute(new SchedulePublicationInput(
                contentId: (string) $content->id,
                revisionId: (string) $content->working_revision_id,
                action: $content->status === 'published' ? 'update' : 'publish',
                runAt: $at->format(DateTimeImmutable::ATOM),
                actorId: $actingPersonId,
                via: $this->code,
            ));
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item($content->fresh());
    }

    /**
     * Sets the item's slug through UpdateContentDetails and the people it
     * credits through SetBylines, when given, inside the caller's
     * transaction, so a refusal of either writes nothing at all.
     *
     * @param  list<string>|null  $credits  credit keys, or null to leave them
     */
    private function details(string $contentId, ?string $slug, ?array $credits, string $actingPersonId): void
    {
        if ($slug !== null) {
            $content = Content::query()->findOrFail($contentId);

            app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
                contentId: $contentId,
                expectedRevisionId: (string) $content->working_revision_id,
                actorId: $actingPersonId,
                slug: $slug,
            ));
        }

        if ($credits !== null) {
            app(SetBylines::class)->execute(new SetBylinesInput($actingPersonId, $contentId, $credits));
        }
    }

    /**
     * Joins these items, the first named first, into one translation group
     * through LinkTranslations.
     *
     * @param  list<string>  $itemIds
     */
    private function link(array $itemIds, string $actingPersonId): void
    {
        app(LinkTranslations::class)->execute(new LinkTranslationsInput($itemIds, $actingPersonId));
    }

    /**
     * Account IDs as SetBylines' credit keys; refused before anything is
     * written when one isn't an account's ID.
     *
     * @param  list<string>  $accountIds
     *
     * @return list<string>
     *
     * @throws WriteRefused
     */
    private static function creditKeys(array $accountIds): array
    {
        $keys = [];

        foreach ($accountIds as $id) {
            if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
                throw new WriteRefused(__('A credit names an account by its ID.'));
            }

            $keys[] = 'user:'.$id;
        }

        return $keys;
    }

    /**
     * Refuses when the person is not the current actor, or none was named.
     */
    private function person(string $actingPersonId): void
    {
        ActingPerson::require($actingPersonId);
    }

    /**
     * Field values keyed as CreateDraft and SaveDraft store them, with the
     * featured image folded into the built-in field when given.
     *
     * @param  array<string, mixed>  $byHandle
     * @param  array<string, mixed>  $current  already-keyed fields to keep when only the image changes
     *
     * @return array<string, mixed>
     */
    private function fields(string $type, array $byHandle, ?MediaRef $featuredImage, array $current = []): array
    {
        $fields = $current === [] ? self::keysFor($type, $byHandle) : [...$current, ...self::keysFor($type, $byHandle)];

        if ($featuredImage !== null) {
            $fields[BuiltInFields::FEATURED_IMAGE] = [
                'id' => $featuredImage->id,
                'alt' => null,
                'decorative' => false,
            ];
        }

        return $fields;
    }

    /**
     * Stored field keys rewritten as handles, so a partial change can merge
     * with handle-keyed updates.
     *
     * @param  array<string, mixed>  $byKey
     *
     * @return array<string, mixed>
     */
    private function fieldsByHandle(string $type, array $byKey): array
    {
        $handles = [];

        foreach (app(FieldRegistry::class)->forType($type) as $field) {
            if (array_key_exists($field->key, $byKey)) {
                $handles[$field->handle] = $byKey[$field->key];
            }
        }

        return $handles;
    }

    /**
     * Field values by handle, keyed as a revision stores them; refused when
     * the type has no such field. The Submissions door uses it too.
     *
     * @param  array<string, mixed>  $byHandle
     *
     * @return array<string, mixed>
     *
     * @throws WriteRefused
     */
    public static function keysFor(string $type, array $byHandle): array
    {
        if ($byHandle === []) {
            return [];
        }

        $map = [];

        foreach (app(FieldRegistry::class)->forType($type) as $field) {
            $map[$field->handle] = $field->key;
        }

        $fields = [];

        foreach ($byHandle as $handle => $value) {
            if (! isset($map[$handle])) {
                throw new WriteRefused(__('These fields do not exist on a :type: :keys', [
                    'type' => $type,
                    'keys' => (string) $handle,
                ]));
            }

            $fields[$map[$handle]] = $value;
        }

        return $fields;
    }

    /**
     * Resolves each entry as a slug, then as a name; a missing name is created
     * through CreateTerm under the acting person's permission.
     *
     * @param  array<string, list<string>>  $byTaxonomy
     *
     * @return list<string>
     */
    private function terms(array $byTaxonomy, string $actingPersonId): array
    {
        $ids = [];

        foreach ($byTaxonomy as $taxonomy => $labels) {
            foreach ($labels as $label) {
                if ($label === '') {
                    throw new WriteRefused(__('Terms must be listed by taxonomy as slugs or names.'));
                }

                $id = Term::query()->where('taxonomy', $taxonomy)->where('slug', $label)->value('id')
                    ?? Term::query()->where('taxonomy', $taxonomy)->where('name', $label)->value('id');

                if ($id === null) {
                    try {
                        $term = app(CreateTerm::class)->execute(new SaveTermInput(
                            taxonomy: $taxonomy,
                            name: $label,
                            slug: null,
                            parentId: null,
                            description: null,
                            actorId: $actingPersonId,
                        ));
                    } catch (Throwable $failure) {
                        $this->refuse($failure);
                    }

                    $id = $term->id;
                }

                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @throws WriteRefused
     */
    private function refuse(Throwable $failure): never
    {
        if ($failure instanceof WriteRefused) {
            throw $failure;
        }

        if ($failure instanceof AuthorizationException) {
            throw new WriteRefused($failure->getMessage() !== '' ? $failure->getMessage() : __('Not permitted.'), previous: $failure);
        }

        if ($failure instanceof ValidationException) {
            $first = collect($failure->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The change was refused.'), previous: $failure);
        }

        throw $failure;
    }
}
