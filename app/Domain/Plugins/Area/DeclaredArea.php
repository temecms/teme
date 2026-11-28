<?php

/**
 * A plugin's area as its manifest declares it, `contributes.area`, read once
 * the validator passed it (docs/plans/plugin-sockets-2.md, A2): its pages
 * and their blocks, and its records' forms, each field with its defaults
 * filled in.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

/**
 * @phpstan-type Field array{key: string, type: string, label: string, description: string, required: bool, maxLength: int|null, min: int|null, max: int|null, options: array<string, string>, default: mixed, visibleWhen: array<string, mixed>, scripts: bool, taxonomy: string|null, storeAs: string|null, choices: string|null, fields: list<array<string, mixed>>, minItems: int, maxItems: int, itemLabel: string, itemTitle: string|null}
 * @phpstan-type Block array<string, mixed>
 * @phpstan-type Page array{id: string, label: string, blocks: list<array<string, mixed>>}
 * @phpstan-type Section array{id: string, label: string, description: string, fields: list<Field>}
 * @phpstan-type Record array{id: string, label: string, plural: string, titleField: string, sections: list<Section>}
 */
final readonly class DeclaredArea
{
    /**
     * @param  list<string>  $purges
     * @param  list<Page>  $pages
     * @param  list<Record>  $records
     */
    public function __construct(
        public string $label,
        public string $description,
        public string $permission,
        public string $menu,
        public array $purges,
        public array $pages,
        public array $records,
        /** The menu icon; plug when the area names none. @since 1.5.0 */
        public string $icon = 'plug',
    ) {}

    /**
     * Reads the declared area; an entry the validator would refuse reads as
     * empty, never as an error.
     *
     * @param  array<array-key, mixed>  $area
     */
    public static function from(array $area): self
    {
        $pages = [];

        foreach (self::list($area['pages'] ?? null) as $page) {
            $pages[] = [
                'id' => self::string($page, 'id'),
                'label' => self::string($page, 'label'),
                'blocks' => array_map(fn (array $block): array => array_filter($block, fn (mixed $value, mixed $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH), self::list($page['blocks'] ?? null)),
            ];
        }

        $records = [];

        foreach (self::list($area['records'] ?? null) as $record) {
            $sections = [];

            foreach (self::list($record['sections'] ?? null) as $section) {
                $sections[] = [
                    'id' => self::string($section, 'id'),
                    'label' => self::string($section, 'label'),
                    'description' => self::string($section, 'description'),
                    'fields' => array_map(self::field(...), self::list($section['fields'] ?? null)),
                ];
            }

            $records[] = [
                'id' => self::string($record, 'id'),
                'label' => self::string($record, 'label'),
                'plural' => self::string($record, 'plural'),
                'titleField' => self::string($record, 'titleField'),
                'sections' => $sections,
            ];
        }

        $icon = self::string($area, 'icon');

        return new self(
            label: self::string($area, 'label'),
            description: self::string($area, 'description'),
            permission: self::string($area, 'permission'),
            menu: self::string($area, 'menu'),
            purges: array_values(array_filter(is_array($area['purges'] ?? null) ? $area['purges'] : [], is_string(...))),
            pages: $pages,
            records: $records,
            icon: $icon !== '' ? $icon : 'plug',
        );
    }

    /**
     * The page with this id, or the first when none is named; null for an
     * id the area doesn't declare.
     *
     * @return Page|null
     */
    public function page(?string $id): ?array
    {
        if ($id === null) {
            return $this->pages[0] ?? null;
        }

        return array_find($this->pages, fn (array $page): bool => $page['id'] === $id);
    }

    /**
     * The block with this id on this page, or null.
     *
     * @return array<string, mixed>|null
     */
    public function block(string $page, string $block): ?array
    {
        $found = $this->page($page);

        return $found === null ? null : array_find($found['blocks'], fn (array $one): bool => ($one['id'] ?? null) === $block);
    }

    /**
     * The record with this id, or null.
     *
     * @return Record|null
     */
    public function record(string $id): ?array
    {
        return array_find($this->records, fn (array $record): bool => $record['id'] === $id);
    }

    /**
     * Every field of the record's form, section by section, in declared order.
     *
     * @return list<Field>
     */
    public function fields(string $record): array
    {
        $found = $this->record($record);
        $fields = [];

        foreach ($found['sections'] ?? [] as $section) {
            array_push($fields, ...$section['fields']);
        }

        return $fields;
    }

    /**
     * The ids of declared actions the area's pages offer, each once: from
     * actions blocks and from custom ids named in a table's rowActions.
     *
     * @return list<string>
     */
    public function offeredActions(): array
    {
        $offered = [];
        $builtIn = ['edit', 'duplicate', 'enable', 'disable', 'delete'];

        foreach ($this->pages as $page) {
            foreach ($page['blocks'] as $block) {
                if (($block['type'] ?? null) === 'actions' && is_array($block['actions'] ?? null)) {
                    array_push($offered, ...array_filter($block['actions'], is_string(...)));
                }

                if (($block['type'] ?? null) === 'table' && is_array($block['rowActions'] ?? null)) {
                    foreach ($block['rowActions'] as $action) {
                        if (is_string($action) && ! in_array($action, $builtIn, true)) {
                            $offered[] = $action;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($offered));
    }

    /**
     * The tables that list this record, each a table block.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(string $record): array
    {
        $tables = [];

        foreach ($this->pages as $page) {
            foreach ($page['blocks'] as $block) {
                if (($block['type'] ?? null) === 'table' && ($block['record'] ?? null) === $record) {
                    $tables[] = $block;
                }
            }
        }

        return $tables;
    }

    /**
     * The actions some table of this record offers, on a row or on the rows chosen.
     *
     * @return list<string>
     */
    public function actions(string $record): array
    {
        $builtIn = ['edit', 'duplicate', 'enable', 'disable', 'delete'];
        $actions = [];

        foreach ($this->tables($record) as $table) {
            foreach (['rowActions', 'bulkActions'] as $kind) {
                foreach (is_array($table[$kind] ?? null) ? $table[$kind] : [] as $action) {
                    if (is_string($action) && in_array($action, $builtIn, true) && ! in_array($action, $actions, true)) {
                        $actions[] = $action;
                    }
                }
            }
        }

        return $actions;
    }

    /**
     * One declared field with every key filled in.
     *
     * @param  array<array-key, mixed>  $field
     *
     * @return Field
     */
    public static function field(array $field): array
    {
        $options = [];

        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $value => $label) {
            $options[(string) $value] = is_string($label) ? $label : '';
        }

        $visibleWhen = [];

        foreach (is_array($field['visibleWhen'] ?? null) ? $field['visibleWhen'] : [] as $key => $value) {
            $visibleWhen[(string) $key] = $value;
        }

        return [
            'key' => self::string($field, 'key'),
            'type' => self::string($field, 'type'),
            'label' => self::string($field, 'label'),
            'description' => self::string($field, 'description'),
            'required' => ($field['required'] ?? false) === true,
            'maxLength' => is_int($field['maxLength'] ?? null) ? $field['maxLength'] : null,
            'min' => is_int($field['min'] ?? null) ? $field['min'] : null,
            'max' => is_int($field['max'] ?? null) ? $field['max'] : null,
            'options' => $options,
            'default' => $field['default'] ?? null,
            'visibleWhen' => $visibleWhen,
            'scripts' => ($field['scripts'] ?? false) === true,
            'taxonomy' => is_string($field['taxonomy'] ?? null) ? $field['taxonomy'] : null,
            'storeAs' => is_string($field['storeAs'] ?? null) ? $field['storeAs'] : null,
            'choices' => ($field['choices'] ?? null) === 'plugin' ? 'plugin' : null,
            'fields' => array_map(self::field(...), self::list($field['fields'] ?? null)),
            'minItems' => is_int($field['minItems'] ?? null) ? $field['minItems'] : 0,
            'maxItems' => is_int($field['maxItems'] ?? null) ? $field['maxItems'] : AreaValidator::DEFAULT_MOST_ITEMS,
            'itemLabel' => is_string($field['itemLabel'] ?? null) ? $field['itemLabel'] : self::string($field, 'label'),
            'itemTitle' => is_string($field['itemTitle'] ?? null) ? $field['itemTitle'] : null,
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function string(array $entry, string $key): string
    {
        return is_string($entry[$key] ?? null) ? $entry[$key] : '';
    }
}
