<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaForms;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * A record's form in a plugin's area (docs/plans/plugin-sockets-2.md, A9):
 * its sections and fields as declared, the choices core draws for content
 * types, terms and the theme's regions, and the values stored, read from
 * the plugin under AreaReads' budgets, or each field's empty value for a
 * new record.
 */
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
     * @return array{record: array{id: string, label: string, plural: string}, id: string|null, title: string, sections: list<array{id: string, label: string, description: string, fields: list<array<string, mixed>>}>, values: array<string, mixed>, failed: string|null, deletable: bool}|null
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
                'fields' => array_map(fn (array $field): array => $this->field($field, $plugin), $section['fields']),
            ], $declared['sections']),
            'values' => $values,
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
            'fields' => array_map(fn (array $sub): array => $this->field($sub, $plugin), is_array($field['fields']) ? array_values($field['fields']) : []),
            'minItems' => $field['minItems'],
            'maxItems' => $field['maxItems'],
            'itemLabel' => $words($field['itemLabel']),
            'itemTitle' => $field['itemTitle'],
            'empty' => AreaForms::empty($field),
        ];
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
