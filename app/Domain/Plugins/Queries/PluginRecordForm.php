<?php

/**
 * A record's form in a plugin's area (docs/plans/plugin-sockets-2.md, A9):
 * its sections and fields as declared, the choices core draws for content
 * types, terms and the theme's regions, and the values stored, read from
 * the plugin under AreaReads' budgets, or each field's empty value for a
 * new record.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\ItemChoices;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaForms;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\RecordHosts;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\DB;

final readonly class PluginRecordForm
{
    /** The most terms a picker offers. */
    public const int MOST_TERMS = 500;

    public function __construct(
        private AreaReads $reads,
        private ContentTypeRegistry $types,
        private RegionDeclarations $regions,
    ) {}

    /**
     * The form's props; null when the record isn't declared, or $id names
     * one the plugin says doesn't exist.
     *
     * @return array{record: array{id: string, label: string, plural: string}, id: string|null, title: string, sections: list<array{id: string, label: string, description: string, fields: list<array<string, mixed>>}>, values: array<string, mixed>, hosts: list<array{host: string, state: string, by: string|null, at: string|null, canManage: bool}>, failed: string|null, deletable: bool}|null
     */
    public function find(AreaPlugin $plugin, string $record, ?string $id, string $personId): ?array
    {
        $declared = $plugin->area->record($record);

        if ($declared === null) {
            return null;
        }

        $fields = $plugin->area->fields($record);
        $failed = null;
        $stored = [];

        if ($id !== null) {
            $read = $this->reads->values($plugin, $record, $id, $fields, AreaContexts::for($plugin->code, $personId), $failed);

            if ($read === null && $failed === null) {
                return null;
            }

            $stored = $read ?? [];
        }

        $values = [];

        foreach ($fields as $field) {
            $values[$field['key']] = array_key_exists($field['key'], $stored) ? $stored[$field['key']] : AreaForms::empty($field);
        }

        $title = $id === null ? '' : (is_string($values[$declared['titleField']] ?? null) ? $values[$declared['titleField']] : '');
        $words = fn (string $english): string => AreaWords::text($plugin->code, $english);

        return [
            'record' => ['id' => $declared['id'], 'label' => $words($declared['label']), 'plural' => $words($declared['plural'])],
            'id' => $id,
            'title' => $title,
            'sections' => array_map(fn (array $section): array => [
                'id' => $section['id'],
                'label' => $words($section['label']),
                'description' => $words($section['description']),
                'fields' => array_map(fn (array $field): array => $this->decorate($this->field($field, $plugin), $values, $personId), $section['fields']),
            ], $declared['sections']),
            'values' => $values,
            'hosts' => $id === null ? [] : $this->hosts($plugin, $id, $personId),
            'failed' => $failed,
            'deletable' => in_array('delete', $plugin->area->actions($record), true),
        ];
    }

    /**
     * One field as the form draws it: its words, its bounds, its rule, and
     * for a select, a picker or a repeater, its choices or sub-fields.
     *
     * @param  array<string, mixed>  $field
     *
     * @return array<string, mixed>
     */
    private function field(array $field, AreaPlugin $plugin): array
    {
        $words = fn (mixed $english): string => AreaWords::text($plugin->code, is_string($english) ? $english : '');
        $options = is_array($field['options']) ? $field['options'] : [];
        $type = (string) $field['type'];

        $choices = match ($type) {
            'select', 'multiselect' => array_map(fn (mixed $value, mixed $label): array => ['value' => (string) $value, 'label' => $words($label)], array_keys($options), array_values($options)),
            'types' => array_map(fn ($contentType): array => ['value' => $contentType->key, 'label' => $contentType->plural], $this->types->all()),
            'terms' => $this->terms(is_string($field['taxonomy']) ? $field['taxonomy'] : ''),
            'region' => array_map(fn (string $slug, array $region): array => ['value' => $slug, 'label' => $region['label']], array_keys($this->regions->forActive()), array_values($this->regions->forActive())),
            default => [],
        };

        return [
            'key' => $field['key'],
            'type' => $type,
            'label' => $words($field['label']),
            'description' => $words($field['description']),
            'required' => $field['required'],
            'min' => $field['min'],
            'max' => $field['max'],
            'maxLength' => $field['maxLength'],
            'visibleWhen' => $field['visibleWhen'],
            'scripts' => $field['scripts'],
            'choices' => $choices,
            'storeAs' => $type === 'terms' ? ($field['storeAs'] ?? 'id') : null,
            'taxonomy' => $type === 'terms' && is_string($field['taxonomy']) ? $field['taxonomy'] : null,
            'chips' => [],
            'stale' => [],
            'fields' => array_map(fn (array $sub): array => $this->field($sub, $plugin), is_array($field['fields']) ? array_values($field['fields']) : []),
            'minItems' => $field['minItems'],
            'maxItems' => $field['maxItems'],
            'itemLabel' => $words($field['itemLabel']),
            'itemTitle' => $field['itemTitle'],
            'empty' => AreaForms::empty($field),
        ];
    }

    /**
     * The outside hosts this record named at its last save, each waiting for
     * agreement or agreed, with whether this person may agree or remove them.
     *
     * @return list<array{host: string, state: string, by: string|null, at: string|null, canManage: bool}>
     */
    private function hosts(AreaPlugin $plugin, string $id, string $personId): array
    {
        $record = PluginRecord::query()->where('name', $plugin->name)->first();
        $consent = $record === null ? [] : $record->consent;
        $agreed = is_array($consent['recordHostsAgreed'] ?? null) ? array_values($consent['recordHostsAgreed']) : [];
        $canManage = app(Actors::class)->find($personId)?->can(PluginAccess::MANAGE) === true;

        return array_values(array_map(function (array $entry) use ($agreed, $canManage): array {
            $found = collect($agreed)->firstWhere('host', $entry['host']);

            return [
                'host' => (string) $entry['host'],
                'state' => is_array($found) ? 'agreed' : 'waiting',
                'by' => is_array($found) ? (string) ($found['by'] ?? '') : null,
                'at' => is_array($found) ? (string) ($found['at'] ?? '') : null,
                'canManage' => $canManage,
            ];
        }, array_filter(RecordHosts::named($consent), fn (array $entry): bool => $entry['record'] === $id)));
    }

    /**
     * What a picker field needs beside its value: the chips of the chosen
     * items (only those the person may open), and for a terms field that
     * stores slugs, each chip's name, and the slugs no term has now.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private function decorate(array $field, array $values, string $personId): array
    {
        $stored = is_array($values[$field['key']] ?? null) ? array_values(array_filter($values[$field['key']], is_string(...))) : [];

        if ($field['type'] === 'items') {
            $readable = AreaForms::readableItems($stored, $personId);
            $found = $readable === [] ? [] : Content::query()->whereIn('id', $readable)->get(['id', 'title', 'type'])->keyBy('id');
            $field['chips'] = array_map(fn (string $id): array => [
                'value' => $id,
                'label' => (string) ($found[$id]->title ?? ''),
                'kind' => ItemChoices::kindLabel((string) ($found[$id]->type ?? '')),
            ], array_values(array_filter($stored, fn (string $id): bool => in_array($id, $readable, true))));
        }

        if ($field['type'] === 'terms' && $field['storeAs'] === 'slug') {
            $taxonomy = is_string($field['taxonomy'] ?? null) ? $field['taxonomy'] : '';
            $names = $stored === [] ? [] : DB::table('terms')->where('taxonomy', $taxonomy)->whereIn('slug', $stored)->pluck('name', 'slug')->all();
            $field['chips'] = array_map(fn (string $slug): array => ['value' => $slug, 'label' => isset($names[$slug]) ? (string) $names[$slug] : null], $stored);
            $field['stale'] = array_values(array_diff($stored, AreaForms::termSlugs($taxonomy, $stored)));
        }

        return $field;
    }

    /**
     * The taxonomy's terms by name, at most MOST_TERMS.
     *
     * @return list<array{value: string, label: string}>
     */
    private function terms(string $taxonomy): array
    {
        return DB::table('terms')->where('taxonomy', $taxonomy)->orderBy('name')->limit(self::MOST_TERMS)->get(['id', 'name'])
            ->map(fn (object $term): array => ['value' => (string) $term->id, 'label' => (string) $term->name])->values()->all();
    }
}
