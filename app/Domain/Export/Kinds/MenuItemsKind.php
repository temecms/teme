<?php

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportKind;
use Illuminate\Support\Facades\DB;

/**
 * Every menu's links, by ID, each pointing at its target by ID (rule 9). A
 * child may come before its parent; position orders siblings. A table's own
 * kind column is written as link_kind.
 */
final readonly class MenuItemsKind implements ExportKind
{
    private const int PAGE = 1000;

    private const string PAGE_SQL = 'SELECT id, navigation_id, parent_id, position, kind, target_id, url, label, opens_new_tab FROM navigation_items WHERE id > ? ORDER BY id LIMIT ?';

    public function name(): string
    {
        return 'menu_items';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every menu\'s links, by ID. A child may come before its parent; `position` orders siblings.';
    }

    public function noun(): string
    {
        return 'menu link|menu links';
    }

    public function fields(): array
    {
        return [
            'id' => 'The link\'s ID.',
            'menu_id' => 'The menu it belongs to.',
            'parent_id' => 'The link it sits under, or null.',
            'position' => 'Its place among its siblings, from 0.',
            'link_kind' => 'What it points to: `content` (an item), `term`, `home` or `url`.',
            'target_id' => 'The item\'s or term\'s ID, for `content` and `term`; otherwise null.',
            'url' => 'The address, for `url`; otherwise null.',
            'label' => 'Its words, or null to show the item\'s or term\'s own title.',
            'opens_new_tab' => 'Whether it opens in a new tab.',
        ];
    }

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            foreach ($rows as $row) {
                yield [
                    'id' => (string) $row->id,
                    'menu_id' => (string) $row->navigation_id,
                    'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
                    'position' => (int) $row->position,
                    'link_kind' => (string) $row->kind,
                    'target_id' => $row->target_id === null ? null : (string) $row->target_id,
                    'url' => $row->url === null ? null : (string) $row->url,
                    'label' => $row->label === null ? null : (string) $row->label,
                    'opens_new_tab' => (bool) $row->opens_new_tab,
                ];
            }

            if ($rows !== []) {
                $after = (string) $rows[array_key_last($rows)]->id;
            }
        } while (count($rows) === $page);
    }
}
