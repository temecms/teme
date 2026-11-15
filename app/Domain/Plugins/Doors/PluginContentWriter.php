<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Hooks\Payloads;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Users\Actors;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Media\MediaRef;
use Throwable;

/**
 * A plugin's content.write door: creates, changes, publishes and schedules
 * items through Teme's Operations, always on behalf of a named person
 * (docs/proposals/plugin-content-write.md).
 */
final readonly class PluginContentWriter implements ContentWriter
{
    public function __construct(private string $code) {}

    public function create(NewItem $item, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);

        try {
            $content = app(CreateDraft::class)->execute(new CreateDraftInput(
                type: $item->type,
                title: $item->title,
                document: $item->body,
                fields: $this->fields($item->type, $item->fields, $item->featuredImage),
                actorId: $actingPersonId,
                terms: $this->terms($item->terms),
                language: $item->language,
                via: $this->code,
            ));
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
            throw new WriteRefused('That item does not exist.');
        }

        /** @var ContentRevision|null $revision */
        $revision = ContentRevision::query()->find($content->working_revision_id);

        if ($revision === null) {
            throw new WriteRefused('That item has no draft to change.');
        }

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
        $terms = $changes->terms === null
            ? array_map('strval', (array) $revision->terms)
            : $this->terms($changes->terms);

        try {
            $content = app(SaveDraft::class)->execute(new SaveDraftInput(
                contentId: (string) $content->id,
                title: $title,
                document: $body,
                fields: $fields,
                expectedRevisionId: (string) $content->working_revision_id,
                actorId: $actingPersonId,
                terms: $terms,
                via: $this->code,
            ));
        } catch (Throwable $failure) {
            $this->refuse($failure);
        }

        return Payloads::item($content);
    }

    public function publish(ItemRef $item, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);
        $content = Content::query()->find($item->id);

        if ($content === null || $content->working_revision_id === null) {
            throw new WriteRefused('That item does not exist.');
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

    public function schedule(ItemRef $item, DateTimeImmutable $at, string $actingPersonId): ItemRef
    {
        $this->person($actingPersonId);
        $content = Content::query()->find($item->id);

        if ($content === null || $content->working_revision_id === null) {
            throw new WriteRefused('That item does not exist.');
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
     * Refuses when no person was named or the ID is unknown.
     */
    private function person(string $actingPersonId): void
    {
        if (trim($actingPersonId) === '' || app(Actors::class)->find($actingPersonId) === null) {
            throw new WriteRefused('A plugin writes on behalf of a person, and none was named.');
        }
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
        $fields = $current === [] ? $this->keysFor($type, $byHandle) : [...$current, ...$this->keysFor($type, $byHandle)];

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
     * @param  array<string, mixed>  $byHandle
     *
     * @return array<string, mixed>
     */
    private function keysFor(string $type, array $byHandle): array
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
     * @param  array<string, list<string>>  $byTaxonomy
     *
     * @return list<string>
     */
    private function terms(array $byTaxonomy): array
    {
        $ids = [];

        foreach ($byTaxonomy as $taxonomy => $slugs) {
            foreach ($slugs as $slug) {
                if ($slug === '') {
                    throw new WriteRefused('Terms must be listed by taxonomy as slugs.');
                }

                $id = Term::query()->where('taxonomy', $taxonomy)->where('slug', $slug)->value('id');

                if ($id === null) {
                    throw new WriteRefused(__('There is no :taxonomy term “:slug”.', [
                        'taxonomy' => $taxonomy,
                        'slug' => $slug,
                    ]));
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
            throw new WriteRefused($failure->getMessage() !== '' ? $failure->getMessage() : 'Not permitted.', previous: $failure);
        }

        if ($failure instanceof ValidationException) {
            $first = collect($failure->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : 'The change was refused.', previous: $failure);
        }

        throw $failure;
    }
}
