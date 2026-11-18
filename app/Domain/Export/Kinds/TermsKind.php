<?php

/**
 * Every term of every taxonomy with its parent by ID, by ID. A child may come
 * before its parent. Never the counts, which any importer rebuilds.
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

final readonly class TermsKind implements ExportKind
{
    public function name(): string
    {
        return 'terms';
    }

    public function version(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Every term of every taxonomy, by ID. A child may come before its parent.';
    }

    public function noun(): string
    {
        return 'term|terms';
    }

    public function fields(): array
    {
        return [
            'id' => 'The term\'s ID.',
            'taxonomy' => 'Its taxonomy\'s key, such as `category` or `tag`.',
            'name' => 'Its name.',
            'slug' => 'The last part of its address.',
            'parent_id' => 'Its parent term, or null.',
            'language' => 'The language it is written in, as a BCP 47 tag; null for the site\'s default language.',
            'translation_group' => 'The same for every language version of one item (or term); null for one with no other version.',
            'description' => 'Its description, or null.',
            'fields' => 'Its fields, as an object.',
            'created_at' => 'When it was made.',
            'updated_at' => 'When it last changed.',
        ];
    }

    private const int PAGE = 1000;

    private const string PAGE_SQL = 'SELECT id, taxonomy, name, slug, parent_id, language, translation_group, description, fields, created_at, updated_at FROM terms WHERE id > ? ORDER BY id LIMIT ?';

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            foreach ($rows as $row) {
                yield [
                    'id' => (string) $row->id,
                    'taxonomy' => (string) $row->taxonomy,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
                    'language' => $row->language === null ? null : (string) $row->language,
                    'translation_group' => $row->translation_group === null ? null : (string) $row->translation_group,
                    'description' => $row->description === null ? null : (string) $row->description,
                    'fields' => ExportJson::decode((string) $row->fields),
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
