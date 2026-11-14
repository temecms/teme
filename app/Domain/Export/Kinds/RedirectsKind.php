<?php

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use Illuminate\Support\Facades\DB;

/**
 * Every redirect, by ID, with its target as an ID or an address. Never the
 * compiled pattern or the derived target path, which an importer rebuilds
 * from source and target. A table's own kind column is written as match.
 */
final readonly class RedirectsKind implements ExportKind
{
    private const int PAGE = 1000;

    private const string PAGE_SQL = 'SELECT id, source, kind, status, target_kind, target, enabled, note, origin, hits, last_hit_at, created_by, updated_by, created_at, updated_at FROM redirects WHERE id > ? ORDER BY id LIMIT ?';

    public function name(): string
    {
        return 'redirects';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every redirect, by ID.';
    }

    public function noun(): string
    {
        return 'redirect|redirects';
    }

    public function fields(): array
    {
        return [
            'id' => 'The redirect\'s ID.',
            'source' => 'The address it answers, relative to the site, without its first or last slash, such as `old-news`; a pattern\'s placeholders in square brackets, such as `news/[slug]`.',
            'match' => '`exact`, or `pattern` when `source` holds placeholders.',
            'status' => 'The answer: 301, 302, 307 or 308 to send readers on; 410 (gone) or 451 (unavailable for legal reasons) to send them nowhere.',
            'target_kind' => 'Where it sends readers: `address` (on this site), `outside`, `content` (an item, wherever it is now) or `media`; null for 410 and 451.',
            'target' => 'The address, or the item\'s or media\'s ID; null for 410 and 451.',
            'enabled' => 'Whether it is switched on.',
            'note' => 'A note about it.',
            'origin' => '`manual` or `import`.',
            'hits' => 'How many times readers have been sent on by it.',
            'last_hit_at' => 'When it last sent a reader, or null.',
            'created_by' => 'Who made it, or null.',
            'updated_by' => 'Who last changed it, or null.',
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
                    'source' => (string) $row->source,
                    'match' => (string) $row->kind,
                    'status' => (int) $row->status,
                    'target_kind' => $row->target_kind === null ? null : (string) $row->target_kind,
                    'target' => $row->target === null ? null : (string) $row->target,
                    'enabled' => (bool) $row->enabled,
                    'note' => $row->note === null ? null : (string) $row->note,
                    'origin' => (string) $row->origin,
                    'hits' => (int) $row->hits,
                    'last_hit_at' => ExportJson::time($row->last_hit_at),
                    'created_by' => $row->created_by === null ? null : (int) $row->created_by,
                    'updated_by' => $row->updated_by === null ? null : (int) $row->updated_by,
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
