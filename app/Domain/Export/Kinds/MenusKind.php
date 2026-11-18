<?php

/**
 * Every menu, by ID. The links are in their own file, which refers to each
 * menu by ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use Illuminate\Support\Facades\DB;

final readonly class MenusKind implements ExportKind
{
    private const int PAGE = 1000;

    private const string PAGE_SQL = 'SELECT id, name, handle, created_at, updated_at FROM navigations WHERE id > ? ORDER BY id LIMIT ?';

    public function name(): string
    {
        return 'menus';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every menu, by ID.';
    }

    public function noun(): string
    {
        return 'menu|menus';
    }

    public function fields(): array
    {
        return [
            'id' => 'The menu\'s ID.',
            'name' => 'Its name.',
            'handle' => 'The name themes ask for it by.',
            'created_at' => 'When it was made.',
            'updated_at' => 'When it last changed.',
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
                    'name' => (string) $row->name,
                    'handle' => (string) $row->handle,
                    'created_at' => ExportJson::time($row->created_at),
                    'updated_at' => ExportJson::time($row->updated_at),
                ];
            }

            if ($rows !== []) {
                $after = (string) $rows[array_key_last($rows)]->id;
            }
        } while (count($rows) === $page);
    }
}
