<?php

/**
 * Checks one of a plugin's suggestions against the item before the person
 * sees it (docs/proposals/plugin-sockets-3.md), and returns what the editor
 * needs to show and apply it: a field the type has, with a value of its
 * kind; a taxonomy the type uses, with the terms that exist and those the
 * person would add; a link to another published, public item, by its ID;
 * a document the editor's own validator accepts.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Api\Live;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\FieldManifest;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;

final class SuggestionCheck
{
    /** The field kinds a suggestion may set, and what each holds. */
    private const array SCALARS = ['text' => 'string', 'textarea' => 'string', 'url' => 'string', 'number' => 'number', 'boolean' => 'boolean', 'select' => 'option'];

    /**
     * The suggestion as the editor draws it.
     *
     * @return array{id: string, label: string, detail: string, change: array<string, mixed>}
     *
     * @throws SuggestionRefused saying why it can't be shown
     */
    public function check(Suggestion $suggestion, Content $content): array
    {
        $change = $suggestion->change;

        return [
            'id' => $suggestion->id,
            'label' => $suggestion->label,
            'detail' => $suggestion->detail,
            'change' => match ($change->kind) {
                SuggestedChange::TITLE => ['kind' => 'title', 'title' => (string) $change->values['title']],
                SuggestedChange::FIELD => $this->field($change, $content),
                SuggestedChange::TERMS => $this->terms($change, $content),
                SuggestedChange::LINK => $this->link($change, $content),
                default => $this->body($change, $content),
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(SuggestedChange $change, Content $content): array
    {
        $handle = (string) $change->values['handle'];
        $value = $change->values['value'];
        $field = null;

        foreach (FieldManifest::forEditor($content->type) as $each) {
            if ($each['handle'] === $handle) {
                $field = $each;
            }
        }

        if ($field === null) {
            throw new SuggestionRefused("The field {$handle} isn’t one of the {$content->type} type’s.");
        }

        $kind = self::SCALARS[$field['type']] ?? null;

        if ($kind === null) {
            throw new SuggestionRefused("The field {$handle} holds {$field['type']}, which a suggestion can’t set.");
        }

        $fits = match (true) {
            $value === null => ! $field['required'],
            $kind === 'string' => is_string($value) && ($field['maxLength'] === null || mb_strlen($value) <= $field['maxLength']),
            $kind === 'number' => is_int($value) || is_float($value),
            $kind === 'boolean' => is_bool($value),
            default => (is_string($value) || is_int($value)) && array_key_exists($value, $field['options']),
        };

        if (! $fits) {
            throw new SuggestionRefused("The value for {$handle} doesn’t fit a {$field['type']} field.");
        }

        return ['kind' => 'field', 'key' => $field['key'], 'handle' => $handle, 'label' => $field['label'], 'value' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(SuggestedChange $change, Content $content): array
    {
        $taxonomy = (string) $change->values['taxonomy'];
        $taxonomies = app(TaxonomyRegistry::class);
        $used = array_map(fn ($each): string => $each->key, $taxonomies->forType($content->type));

        if (! in_array($taxonomy, $used, true)) {
            throw new SuggestionRefused("The {$content->type} type doesn’t use the taxonomy {$taxonomy}.");
        }

        $found = [];
        $create = [];

        foreach ((array) $change->values['terms'] as $wanted) {
            $wanted = (string) $wanted;
            $term = Term::query()
                ->where('taxonomy', $taxonomy)
                ->where(fn ($query) => $query->where('slug', $wanted)->orWhereRaw('lower(name) = lower(?)', [$wanted]))
                ->orderByRaw('(language IS NOT DISTINCT FROM ?) DESC', [$content->language])
                ->orderBy('id')
                ->first(['id', 'name', 'taxonomy']);

            if ($term === null) {
                $create[] = $wanted;
            } else {
                $found[] = ['id' => $term->id, 'name' => $term->name, 'taxonomy' => $term->taxonomy];
            }
        }

        return ['kind' => 'terms', 'taxonomy' => $taxonomy, 'taxonomyLabel' => $taxonomies->get($taxonomy)->plural, 'terms' => $found, 'create' => $create];
    }

    /**
     * @return array<string, mixed>
     */
    private function link(SuggestedChange $change, Content $content): array
    {
        $itemId = (string) $change->values['itemId'];

        if ($itemId === $content->id) {
            throw new SuggestionRefused('A link can’t lead to the item itself.');
        }

        $live = Content::query();
        Live::publicOnly(Live::apply($live->getQuery(), null));
        $target = $live->whereKey($itemId)->first(['contents.id', 'contents.published_revision_id']);

        if ($target === null) {
            throw new SuggestionRefused("The item {$itemId} isn’t published for everyone to read.");
        }

        return ['kind' => 'link', 'phrase' => (string) $change->values['phrase'], 'itemId' => $itemId, 'occurrence' => (int) $change->values['occurrence'], 'target' => (string) ContentRevision::query()->whereKey($target->published_revision_id)->value('title')];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(SuggestedChange $change, Content $content): array
    {
        $document = DocumentNormaliser::normalise((array) $change->values['document']);

        try {
            app(DocumentValidator::class)->validate($document);
        } catch (ValidationException $refused) {
            throw new SuggestionRefused('The suggested document isn’t one the editor accepts: '.collect($refused->errors())->flatten()->first());
        }

        return ['kind' => 'body', 'document' => $document];
    }
}
