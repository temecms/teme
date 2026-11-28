<?php

/**
 * Checks a plugin's declared permissions and area against every bound of
 * docs/plans/plugin-sockets-2.md (A1, A2), each refusal one plain sentence,
 * before anything of them is made or drawn.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Editor\PanelValidator;
use Teme\Contracts\Lists\Cell;

final class AreaValidator
{
    /** The places an area's menu entry may go. */
    public const array MENUS = ['content', 'site', 'tools'];

    /** The page-cache tags a write in an area may purge. */
    public const array PURGES = ['insertions', 'page-insertions'];

    /** The blocks a page may hold. */
    public const array BLOCKS = ['figures', 'chart', 'table', 'actions', 'notice'];

    /** A figure's formats. */
    public const array FORMATS = ['number', 'percent', 'text'];

    /** A chart's kinds. */
    public const array CHART_KINDS = ['line', 'bar'];

    /** The built-in actions a row may offer. */
    public const array ROW_ACTIONS = ['edit', 'duplicate', 'enable', 'disable', 'delete'];

    /**
     * The kinds a table column may take: list kinds, plus a link to an outside address.
     *
     * @since next
     */
    public const array COLUMN_KINDS = ['text', 'number', 'badge', 'link'];

    /** The actions a table may run on the rows chosen. */
    public const array BULK_ACTIONS = ['duplicate', 'enable', 'disable', 'delete'];

    /** The types a record's field may take. */
    public const array FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'email', 'url', 'color', 'code', 'datetime', 'types', 'terms', 'region', 'repeater'];

    /** The most pages, blocks a page, records, sections a form, fields a section and fields a form. */
    public const int MOST_PAGES = 8;

    public const int MOST_BLOCKS = 8;

    public const int MOST_RECORDS = 3;

    public const int MOST_SECTIONS = 8;

    public const int MOST_SECTION_FIELDS = 30;

    public const int MOST_FORM_FIELDS = 60;

    /** The most items a repeater may hold, and its default. */
    public const int MOST_ITEMS = 50;

    public const int DEFAULT_MOST_ITEMS = 20;

    /** The most rows a table page may show. */
    public const int MOST_PER_PAGE = 100;

    /** The longest code value, in characters. */
    public const int MOST_CODE = 65_535;

    /** A block's, page's, record's, section's, item's or series' id. */
    public const string ID = '/^[a-z][a-z0-9-]{1,39}$/D';

    /** The most sections a plugin's settings page splits into. */
    public const int MOST_SETTINGS_SECTIONS = 8;

    /** The most panels a plugin adds to Home. @since 1.8.0 */
    public const int MOST_HOME_PANELS = 3;

    /** The most blocks one Home panel shows. @since 1.8.0 */
    public const int MOST_HOME_BLOCKS = 4;

    /** The kinds of block a Home panel may show: a table as its first rows, read only. @since 1.8.0 */
    public const array HOME_BLOCKS = ['figures', 'chart', 'table', 'notice'];

    /**
     * Why the declared permissions can't be used, or nothing.
     *
     * @return list<string>
     */
    public function permissionErrors(mixed $permissions): array
    {
        if (! is_array($permissions) || ! array_is_list($permissions)) {
            return [__('A plugin’s permissions must be a list.')];
        }

        $errors = [];

        if (count($permissions) > PluginPermissions::MOST) {
            $errors[] = __('A plugin can declare at most 3 permissions.');
        }

        $ids = [];

        foreach ($permissions as $permission) {
            $permission = is_array($permission) ? $permission : [];
            $id = is_string($permission['id'] ?? null) ? $permission['id'] : null;

            if (preg_match(PluginPermissions::ID, $id ?? '') !== 1) {
                $errors[] = __('A permission’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if (! $this->text($permission['label'] ?? null, 40)) {
                $errors[] = __('A permission needs a label of 1 to 40 characters.');
            }

            if (array_key_exists('description', $permission) && ! $this->text($permission['description'], 160, true)) {
                $errors[] = __('A permission’s description must be text of at most 160 characters.');
            }

            $roles = $permission['roles'] ?? [];

            if (! is_array($roles) || ! array_is_list($roles) || array_diff(array_map(fn (mixed $role): string => is_string($role) ? $role : '', $roles), PluginPermissions::ROLES) !== []) {
                $errors[] = __('A permission’s roles must be editor, author or contributor; administrators always hold it.');
            }

            foreach (array_keys($permission) as $key) {
                if (! in_array($key, ['id', 'label', 'description', 'roles'], true)) {
                    $errors[] = __('A permission can’t have :key.', ['key' => (string) $key]);
                }
            }

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two permissions can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $errors;
    }

    /**
     * Why the declared area can't be used, or nothing.
     *
     * @param  array<array-key, mixed>  $contributes  the manifest's `contributes`, for its permissions and actions
     *
     * @return list<string>
     */
    public function areaErrors(mixed $area, array $contributes): array
    {
        if (! is_array($area) || array_is_list($area)) {
            return [__('A plugin’s area must be an object.')];
        }

        $errors = [];
        $permissions = array_column(array_filter(is_array($contributes['permissions'] ?? null) ? $contributes['permissions'] : [], is_array(...)), 'id');
        $declaredActions = array_values(array_filter(is_array($contributes['actions'] ?? null) ? $contributes['actions'] : [], is_array(...)));
        $actions = array_column($declaredActions, 'id');
        $rowMarked = [];

        foreach ($declaredActions as $action) {
            if (($action['row'] ?? false) === true && is_string($action['id'] ?? null)) {
                $rowMarked[] = $action['id'];
            }
        }

        foreach (array_keys($area) as $key) {
            if (! in_array($key, ['label', 'description', 'permission', 'menu', 'icon', 'purges', 'pages', 'records'], true)) {
                $errors[] = __('An area can’t have :key.', ['key' => (string) $key]);
            }
        }

        if (! $this->text($area['label'] ?? null, 40)) {
            $errors[] = __('An area needs a label of 1 to 40 characters.');
        }

        if (array_key_exists('description', $area) && ! $this->text($area['description'], 160, true)) {
            $errors[] = __('An area’s description must be text of at most 160 characters.');
        }

        if (! in_array($area['permission'] ?? null, $permissions, true)) {
            $errors[] = __('An area must name one of the permissions the plugin declares.');
        }

        if (! in_array($area['menu'] ?? null, self::MENUS, true)) {
            $errors[] = __('An area’s menu must be content, site or tools.');
        }

        if (array_key_exists('icon', $area) && ! in_array($area['icon'], PanelValidator::icons(), true)) {
            $errors[] = __(':icon isn’t an icon a plugin can use.', ['icon' => is_scalar($area['icon']) ? (string) $area['icon'] : '']);
        }

        $purges = $area['purges'] ?? [];

        if (! is_array($purges) || ! array_is_list($purges) || array_diff(array_map(fn (mixed $tag): string => is_string($tag) ? $tag : '', $purges), self::PURGES) !== []) {
            $errors[] = __('An area may purge only insertions and page-insertions.');
        }

        $records = $area['records'] ?? [];
        $recordIds = [];

        if (! is_array($records) || ! array_is_list($records) || count($records) > self::MOST_RECORDS) {
            $errors[] = __('An area holds at most 3 kinds of record.');
            $records = [];
        }

        foreach ($records as $record) {
            $record = is_array($record) ? $record : [];
            $id = is_string($record['id'] ?? null) ? $record['id'] : null;
            array_push($errors, ...$this->recordErrors($record));

            if ($id !== null && in_array($id, $recordIds, true)) {
                $errors[] = __('Two records can’t share the id :id.', ['id' => $id]);
            }

            $recordIds[] = $id;
        }

        $pages = $area['pages'] ?? null;

        if (! is_array($pages) || ! array_is_list($pages) || $pages === [] || count($pages) > self::MOST_PAGES) {
            $errors[] = __('An area needs 1 to 8 pages.');
            $pages = [];
        }

        $pageIds = [];

        foreach ($pages as $page) {
            $page = is_array($page) ? $page : [];
            $id = is_string($page['id'] ?? null) ? $page['id'] : null;
            array_push($errors, ...$this->pageErrors($page, array_values(array_filter($recordIds, is_string(...))), $actions, $rowMarked));

            if ($id !== null && in_array($id, $pageIds, true)) {
                $errors[] = __('Two pages can’t share the id :id.', ['id' => $id]);
            }

            $pageIds[] = $id;
        }

        return array_values(array_unique($errors));
    }

    /**
     * Why the Home panels can't be used, or nothing: each names a page of
     * the area and one to four of its blocks, of a kind Home shows.
     *
     * @param  array<array-key, mixed>|null  $area  the manifest's area, read for its pages
     *
     * @return list<string>
     */
    public function homePanelErrors(mixed $panels, mixed $area): array
    {
        if (! is_array($panels) || ! array_is_list($panels) || $panels === [] || count($panels) > self::MOST_HOME_PANELS) {
            return [__('A plugin adds 1 to 3 panels to Home.')];
        }

        if (! is_array($area)) {
            return [__('A plugin’s Home panels need its area.')];
        }

        $blocksByPage = [];

        foreach (is_array($area['pages'] ?? null) ? $area['pages'] : [] as $page) {
            if (is_array($page) && is_string($page['id'] ?? null)) {
                $blocksByPage[$page['id']] = array_column(array_filter(is_array($page['blocks'] ?? null) ? $page['blocks'] : [], is_array(...)), 'type', 'id');
            }
        }

        $errors = [];
        $ids = [];

        foreach ($panels as $panel) {
            $panel = is_array($panel) ? $panel : [];
            $id = is_string($panel['id'] ?? null) ? $panel['id'] : null;

            array_push($errors, ...$this->unknownKeys($panel, ['id', 'title', 'size', 'page', 'blocks']));

            if (preg_match(self::ID, $id ?? '') !== 1) {
                $errors[] = __('A Home panel’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two Home panels can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;

            if (! $this->text($panel['title'] ?? null, 40)) {
                $errors[] = __('A Home panel needs a title of 1 to 40 characters.');
            }

            if (array_key_exists('size', $panel) && ! in_array($panel['size'], ['small', 'medium', 'large', 'full'], true)) {
                $errors[] = __('A Home panel’s size must be small, medium, large or full.');
            }

            $page = is_string($panel['page'] ?? null) ? $panel['page'] : null;

            if ($page === null || ! array_key_exists($page, $blocksByPage)) {
                $errors[] = __('A Home panel must name a page of its area.');
            }

            $blocks = $panel['blocks'] ?? null;

            if (! is_array($blocks) || ! array_is_list($blocks) || $blocks === [] || count($blocks) > self::MOST_HOME_BLOCKS) {
                $errors[] = __('A Home panel shows 1 to 4 blocks.');

                continue;
            }

            foreach ($blocks as $blockId) {
                $type = is_string($blockId) && $page !== null ? ($blocksByPage[$page][$blockId] ?? null) : null;

                if ($type === null) {
                    $errors[] = __('A Home panel can only show blocks of its own page.');
                } elseif (! in_array($type, self::HOME_BLOCKS, true)) {
                    $errors[] = __('A Home panel shows only figures, charts, tables and notices.');
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<array-key, mixed>  $page
     * @param  list<string>  $records
     * @param  list<string>  $actions
     * @param  list<string>  $rowMarked  declared actions marked for table rows
     *
     * @return list<string>
     */
    private function pageErrors(array $page, array $records, array $actions, array $rowMarked): array
    {
        $errors = [];

        foreach (array_keys($page) as $key) {
            if (! in_array($key, ['id', 'label', 'blocks'], true)) {
                $errors[] = __('A page can’t have :key.', ['key' => (string) $key]);
            }
        }

        if (preg_match(self::ID, is_string($page['id'] ?? null) ? $page['id'] : '') !== 1 || in_array($page['id'] ?? null, ['records', 'settings'], true)) {
            $errors[] = __('A page’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter, and not records or settings.');
        }

        if (! $this->text($page['label'] ?? null, 40)) {
            $errors[] = __('A page needs a label of 1 to 40 characters.');
        }

        $blocks = $page['blocks'] ?? null;

        if (! is_array($blocks) || ! array_is_list($blocks) || $blocks === [] || count($blocks) > self::MOST_BLOCKS) {
            return [...$errors, __('A page needs 1 to 8 blocks.')];
        }

        $ids = [];

        foreach ($blocks as $block) {
            $block = is_array($block) ? $block : [];
            $id = is_string($block['id'] ?? null) ? $block['id'] : null;

            if (preg_match(self::ID, $id ?? '') !== 1) {
                $errors[] = __('A block’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two blocks of a page can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;

            array_push($errors, ...match ($block['type'] ?? null) {
                'figures' => $this->figuresErrors($block),
                'chart' => $this->chartErrors($block),
                'table' => $this->tableErrors($block, $records, $rowMarked),
                'actions' => $this->actionsErrors($block, $actions),
                'notice' => $this->noticeErrors($block),
                default => [__('A block’s type must be figures, chart, table, actions or notice.')],
            });
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $block
     *
     * @return list<string>
     */
    private function figuresErrors(array $block): array
    {
        $errors = $this->unknownKeys($block, ['type', 'id', 'items']);
        $items = $block['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 4) {
            return [...$errors, __('A figures block holds 1 to 4 figures.')];
        }

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];

            if (preg_match(self::ID, is_string($item['id'] ?? null) ? $item['id'] : '') !== 1 || ! $this->text($item['label'] ?? null, 60)) {
                $errors[] = __('A figure needs an id and a label of 1 to 60 characters.');
            }

            if (! in_array($item['format'] ?? null, self::FORMATS, true)) {
                $errors[] = __('A figure’s format must be number, percent or text.');
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $block
     *
     * @return list<string>
     */
    private function chartErrors(array $block): array
    {
        $errors = $this->unknownKeys($block, ['type', 'id', 'label', 'kind', 'series']);

        if (! $this->text($block['label'] ?? null, 60)) {
            $errors[] = __('A chart needs a label of 1 to 60 characters.');
        }

        if (! in_array($block['kind'] ?? null, self::CHART_KINDS, true)) {
            $errors[] = __('A chart’s kind must be line or bar.');
        }

        $series = $block['series'] ?? null;

        if (! is_array($series) || ! array_is_list($series) || $series === [] || count($series) > 3) {
            return [...$errors, __('A chart has 1 to 3 series.')];
        }

        foreach ($series as $one) {
            $one = is_array($one) ? $one : [];

            if (preg_match(self::ID, is_string($one['id'] ?? null) ? $one['id'] : '') !== 1 || ! $this->text($one['label'] ?? null, 40)) {
                $errors[] = __('A chart’s series needs an id and a label of 1 to 40 characters.');
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @param  list<string>  $records
     * @param  list<string>  $rowMarked  declared actions marked for table rows
     *
     * @return list<string>
     */
    private function tableErrors(array $block, array $records, array $rowMarked): array
    {
        $errors = $this->unknownKeys($block, ['type', 'id', 'record', 'columns', 'search', 'filters', 'perPage', 'rowActions', 'bulkActions']);

        if (! in_array($block['record'] ?? null, $records, true)) {
            $errors[] = __('A table must name one of the area’s records.');
        }

        $columns = $block['columns'] ?? null;

        if (! is_array($columns) || ! array_is_list($columns) || $columns === [] || count($columns) > ContributionsValidator::MOST_LIST_COLUMNS) {
            $errors[] = __('A table needs 1 to 6 columns.');
            $columns = [];
        }

        foreach ($columns as $column) {
            $column = is_array($column) ? $column : [];
            array_push($errors, ...$this->unknownKeys($column, ['id', 'label', 'kind', 'sortable']));

            if (preg_match(self::ID, is_string($column['id'] ?? null) ? $column['id'] : '') !== 1 || ! $this->text($column['label'] ?? null, 40)) {
                $errors[] = __('A table’s column needs an id and a label of 1 to 40 characters.');
            }

            if (! in_array($column['kind'] ?? null, self::COLUMN_KINDS, true)) {
                $errors[] = __('A column’s kind must be text, number, badge or link.');
            }

            if (array_key_exists('sortable', $column) && ! is_bool($column['sortable'])) {
                $errors[] = __('A column’s sortable must be true or false.');
            }
        }

        if (array_key_exists('search', $block) && ! is_bool($block['search'])) {
            $errors[] = __('A table’s search must be true or false.');
        }

        $filters = $block['filters'] ?? [];

        if (! is_array($filters) || ! array_is_list($filters) || count($filters) > 3) {
            $errors[] = __('A table has at most 3 filters.');
            $filters = [];
        }

        foreach ($filters as $filter) {
            $filter = is_array($filter) ? $filter : [];

            if (preg_match(self::ID, is_string($filter['id'] ?? null) ? $filter['id'] : '') !== 1 || ! $this->text($filter['label'] ?? null, 40) || ! $this->options($filter['options'] ?? null)) {
                $errors[] = __('A table’s filter needs an id, a label and its options.');
            }
        }

        $perPage = $block['perPage'] ?? 20;

        if (! is_int($perPage) || $perPage < 1 || $perPage > self::MOST_PER_PAGE) {
            $errors[] = __('A table shows 1 to 100 rows a page.');
        }

        $allowedRow = [...self::ROW_ACTIONS, ...$rowMarked];

        if (! $this->subset($block['rowActions'] ?? [], $allowedRow)) {
            $errors[] = __('A table’s row actions must be edit, duplicate, enable, disable, delete, or a declared action marked for rows.');
        }

        $customInTable = array_values(array_intersect(is_array($block['rowActions'] ?? null) ? $block['rowActions'] : [], $rowMarked));

        if (count($customInTable) > ContributionsValidator::MOST_ROW_ACTIONS) {
            $errors[] = __('A table offers at most 2 custom row actions.');
        }

        if (! $this->subset($block['bulkActions'] ?? [], self::BULK_ACTIONS)) {
            $errors[] = __('A table’s bulk actions must be duplicate, enable, disable or delete.');
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @param  array<array-key, mixed>  $actions
     *
     * @return list<string>
     */
    private function actionsErrors(array $block, array $actions): array
    {
        $errors = $this->unknownKeys($block, ['type', 'id', 'actions']);
        $named = $block['actions'] ?? null;

        if (! is_array($named) || ! array_is_list($named) || $named === [] || count($named) > 3 || array_diff(array_map(fn (mixed $id): string => is_string($id) ? $id : '', $named), array_map(strval(...), $actions)) !== []) {
            $errors[] = __('An actions block names 1 to 3 of the plugin’s declared actions.');
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $block
     *
     * @return list<string>
     */
    private function noticeErrors(array $block): array
    {
        $errors = $this->unknownKeys($block, ['type', 'id', 'text', 'tone']);

        if (! $this->text($block['text'] ?? null, 300)) {
            $errors[] = __('A notice needs text of 1 to 300 characters.');
        }

        if (array_key_exists('tone', $block) && ! in_array($block['tone'], Cell::TONES, true)) {
            $errors[] = __('A notice’s tone must be ok, neutral, info, warn or danger.');
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $record
     *
     * @return list<string>
     */
    private function recordErrors(array $record): array
    {
        $errors = $this->unknownKeys($record, ['id', 'label', 'plural', 'titleField', 'sections']);

        if (preg_match(self::ID, is_string($record['id'] ?? null) ? $record['id'] : '') !== 1) {
            $errors[] = __('A record’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
        }

        if (! $this->text($record['label'] ?? null, 40) || ! $this->text($record['plural'] ?? null, 40)) {
            $errors[] = __('A record needs a label and a plural of 1 to 40 characters each.');
        }

        $sections = $record['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections) || $sections === [] || count($sections) > self::MOST_SECTIONS) {
            return [...$errors, __('A record’s form needs 1 to 8 sections.')];
        }

        $keys = [];
        $count = 0;
        $sectionIds = [];

        foreach ($sections as $section) {
            $section = is_array($section) ? $section : [];
            array_push($errors, ...$this->unknownKeys($section, ['id', 'label', 'description', 'fields']));
            $sectionId = is_string($section['id'] ?? null) ? $section['id'] : null;

            if (preg_match(self::ID, $sectionId ?? '') !== 1 || ! $this->text($section['label'] ?? null, 60)) {
                $errors[] = __('A section needs an id and a label of 1 to 60 characters.');
            }

            if ($sectionId !== null && in_array($sectionId, $sectionIds, true)) {
                $errors[] = __('Two sections can’t share the id :id.', ['id' => $sectionId]);
            }

            $sectionIds[] = $sectionId;
            $fields = $section['fields'] ?? null;

            if (! is_array($fields) || ! array_is_list($fields) || $fields === [] || count($fields) > self::MOST_SECTION_FIELDS) {
                $errors[] = __('A section holds 1 to 30 fields.');

                continue;
            }

            foreach ($fields as $field) {
                $field = is_array($field) ? $field : [];
                $count++;
                array_push($errors, ...$this->fieldErrors($field, $keys, true));

                if (is_array($field['fields'] ?? null)) {
                    $count += count($field['fields']);
                }

                $keys[is_string($field['key'] ?? null) ? $field['key'] : ''] = $field;
            }
        }

        if ($count > self::MOST_FORM_FIELDS) {
            $errors[] = __('A record’s form holds at most 60 fields, a repeater’s counted.');
        }

        if (! array_key_exists(is_string($record['titleField'] ?? null) ? $record['titleField'] : '', $keys)) {
            $errors[] = __('A record’s titleField must name one of its fields.');
        }

        return $errors;
    }

    /**
     * The errors of one field, given the keys declared before it at its level.
     *
     * @param  array<array-key, mixed>  $field
     * @param  array<string, array<array-key, mixed>>  $earlier  the fields before it at the same level, by key
     *
     * @return list<string>
     */
    public function fieldErrors(array $field, array $earlier, bool $repeaterAllowed): array
    {
        $type = $field['type'] ?? null;
        $key = is_string($field['key'] ?? null) ? $field['key'] : '';
        $errors = $this->unknownKeys($field, ['key', 'type', 'label', 'description', 'required', 'maxLength', 'min', 'max', 'options', 'default', 'visibleWhen', 'scripts', 'taxonomy', 'fields', 'minItems', 'maxItems', 'itemLabel', 'itemTitle']);

        if (preg_match(ContributionsValidator::KEY, $key) !== 1) {
            $errors[] = __('A field’s key must be 2 to 41 lower-case letters, digits and underscores, starting with a letter.');
        }

        if (array_key_exists($key, $earlier)) {
            $errors[] = __('Two fields can’t share the key :key.', ['key' => $key]);
        }

        if (! in_array($type, self::FIELD_TYPES, true) || ($type === 'repeater' && ! $repeaterAllowed)) {
            $errors[] = __('The field :key has a type a form can’t draw.', ['key' => $key]);
        }

        if (! $this->text($field['label'] ?? null, 60)) {
            $errors[] = __('The field :key needs a label of 1 to 60 characters.', ['key' => $key]);
        }

        if (array_key_exists('description', $field) && ! $this->text($field['description'], 300, true)) {
            $errors[] = __('The field :key’s description must be text of at most 300 characters.', ['key' => $key]);
        }

        if (array_key_exists('required', $field) && ! is_bool($field['required'])) {
            $errors[] = __('The field :key’s required must be true or false.', ['key' => $key]);
        }

        foreach (['maxLength', 'min', 'max'] as $bound) {
            if (array_key_exists($bound, $field) && ! is_int($field[$bound])) {
                $errors[] = __('The field :key’s :bound must be a whole number.', ['key' => $key, 'bound' => $bound]);
            }
        }

        if (in_array($type, ['select', 'multiselect'], true) && ! $this->options($field['options'] ?? null)) {
            $errors[] = __('The field :key needs its options.', ['key' => $key]);
        }

        if (array_key_exists('scripts', $field) && ($type !== 'code' || ! is_bool($field['scripts']))) {
            $errors[] = __('Only a code field may say whether it holds scripts, true or false.');
        }

        if ($type === 'terms' && ! (is_string($field['taxonomy'] ?? null) && preg_match(ContributionsValidator::TYPE_KEY, $field['taxonomy']) === 1)) {
            $errors[] = __('The field :key must name its taxonomy.', ['key' => $key]);
        }

        if ($type === 'repeater') {
            array_push($errors, ...$this->repeaterErrors($field, $key));
        }

        if (array_key_exists('visibleWhen', $field)) {
            array_push($errors, ...$this->conditionErrors(false, $key, $field['visibleWhen'], $earlier));
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $field
     *
     * @return list<string>
     */
    private function repeaterErrors(array $field, string $key): array
    {
        $errors = [];
        $min = $field['minItems'] ?? 0;
        $max = $field['maxItems'] ?? self::DEFAULT_MOST_ITEMS;

        if (! is_int($min) || $min < 0 || $min > self::MOST_ITEMS || ! is_int($max) || $max < 1 || $max > self::MOST_ITEMS || $min > $max) {
            $errors[] = __('The repeater :key holds between 0 and 50 items, its minItems no more than its maxItems.', ['key' => $key]);
        }

        if (array_key_exists('itemLabel', $field) && ! $this->text($field['itemLabel'], 40)) {
            $errors[] = __('The repeater :key’s itemLabel must be 1 to 40 characters.', ['key' => $key]);
        }

        $fields = $field['fields'] ?? null;

        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            return [...$errors, __('The repeater :key needs its fields.', ['key' => $key])];
        }

        $earlier = [];

        foreach ($fields as $sub) {
            $sub = is_array($sub) ? $sub : [];
            array_push($errors, ...$this->fieldErrors($sub, $earlier, false));
            $earlier[is_string($sub['key'] ?? null) ? $sub['key'] : ''] = $sub;
        }

        if (array_key_exists('itemTitle', $field) && ! array_key_exists(is_string($field['itemTitle']) ? $field['itemTitle'] : '', $earlier)) {
            $errors[] = __('The repeater :key’s itemTitle must name one of its fields.', ['key' => $key]);
        }

        return $errors;
    }

    /**
     * The sections a plugin's settings page is split into (A4): at most 8,
     * each an id, a label and, maybe, a line.
     *
     * @return list<string>
     */
    public function settingsSectionsErrors(mixed $sections): array
    {
        if (! is_array($sections) || ! array_is_list($sections)) {
            return [__('A plugin’s settings sections must be a list.')];
        }

        if (count($sections) > self::MOST_SETTINGS_SECTIONS) {
            return [__('A plugin’s settings have at most 8 sections.')];
        }

        $errors = [];
        $ids = [];

        foreach ($sections as $section) {
            $section = is_array($section) ? $section : [];
            $id = is_string($section['id'] ?? null) ? $section['id'] : '';
            array_push($errors, ...$this->unknownKeys($section, ['id', 'label', 'description']));

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('A settings section’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two settings sections can’t share the id :id.', ['id' => $id]);
            }

            if (! $this->text($section['label'] ?? null, 40)) {
                $errors[] = __('A settings section needs a label of 1 to 40 characters.');
            }

            if (array_key_exists('description', $section) && ! $this->text($section['description'], 160, true)) {
                $errors[] = __('A settings section’s description must be text of at most 160 characters.');
            }

            $ids[] = $id;
        }

        return $errors;
    }

    /**
     * A setting's `section` and `visibleWhen` (A4), given the sections the
     * plugin declares and the settings before it.
     *
     * @param  array<array-key, mixed>  $setting
     * @param  list<string>  $sections
     * @param  array<string, array<array-key, mixed>>  $earlier
     *
     * @return list<string>
     */
    public function settingPlacementErrors(array $setting, array $sections, array $earlier): array
    {
        $key = is_string($setting['key'] ?? null) ? $setting['key'] : '';
        $errors = [];

        if (array_key_exists('section', $setting) && ! in_array($setting['section'], $sections, true)) {
            $errors[] = __('The setting “:key” names the section “:section”, which the plugin doesn’t declare.', ['key' => $key, 'section' => is_scalar($setting['section']) ? (string) $setting['section'] : '']);
        }

        if (array_key_exists('visibleWhen', $setting)) {
            array_push($errors, ...$this->conditionErrors(true, $key, $setting['visibleWhen'], $earlier));
        }

        return $errors;
    }

    /**
     * A field's or a setting's `visibleWhen`, by the same rule as a theme's
     * settings: earlier keys of the same level, each to a value or a list of
     * values, a select's among its options.
     *
     * @param  array<string, array<array-key, mixed>>  $earlier  the declarations before it, by key
     *
     * @return list<string>
     */
    public function conditionErrors(bool $setting, string $key, mixed $conditions, array $earlier): array
    {
        if (! is_array($conditions) || $conditions === [] || array_is_list($conditions)) {
            return [$setting
                ? __('The setting “:key” has a visibleWhen that must map settings declared before it to a value, or a list of values.', ['key' => $key])
                : __('The field “:key” has a visibleWhen that must map fields declared before it to a value, or a list of values.', ['key' => $key])];
        }

        $errors = [];

        foreach ($conditions as $other => $value) {
            $other = (string) $other;
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            $options = is_array($earlier[$other]['options'] ?? null) && in_array($earlier[$other]['type'] ?? null, ['select', 'multiselect'], true) ? array_map(strval(...), array_keys($earlier[$other]['options'])) : null;

            if (! array_key_exists($other, $earlier)) {
                $errors[] = $setting
                    ? __('The setting “:key” shows when “:other” has a value, and “:other” must be declared before it.', ['key' => $key, 'other' => $other])
                    : __('The field “:key” shows when “:other” has a value, and “:other” must be declared before it.', ['key' => $key, 'other' => $other]);
            } elseif ($values === [] || array_filter($values, fn (mixed $item): bool => ! is_scalar($item)) !== []) {
                $errors[] = $setting
                    ? __('The setting “:key” shows when “:other” has a value, or one of a list of values; this isn’t either.', ['key' => $key, 'other' => $other])
                    : __('The field “:key” shows when “:other” has a value, or one of a list of values; this isn’t either.', ['key' => $key, 'other' => $other]);
            } elseif ($options !== null) {
                foreach ($values as $wanted) {
                    if (! in_array((string) $wanted, $options, true)) {
                        $errors[] = $setting
                            ? __('The setting “:key” shows when “:other” is “:value”, which isn’t one of its options.', ['key' => $key, 'other' => $other, 'value' => (string) $wanted])
                            : __('The field “:key” shows when “:other” is “:value”, which isn’t one of its options.', ['key' => $key, 'other' => $other, 'value' => (string) $wanted]);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @param  list<string>  $allowed
     *
     * @return list<string>
     */
    private function unknownKeys(array $entry, array $allowed): array
    {
        $errors = [];

        foreach (array_keys($entry) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = __(':key isn’t something this part of an area can have.', ['key' => (string) $key]);
            }
        }

        return $errors;
    }

    private function text(mixed $value, int $most, bool $emptyAllowed = false): bool
    {
        return is_string($value) && ($emptyAllowed || trim($value) !== '') && mb_strlen($value) <= $most;
    }

    private function options(mixed $options): bool
    {
        if (! is_array($options) || $options === [] || array_is_list($options)) {
            return false;
        }

        foreach ($options as $label) {
            if (! is_string($label) || trim($label) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function subset(mixed $list, array $allowed): bool
    {
        return is_array($list) && array_is_list($list) && array_diff(array_map(fn (mixed $item): string => is_string($item) ? $item : '', $list), $allowed) === [];
    }
}
