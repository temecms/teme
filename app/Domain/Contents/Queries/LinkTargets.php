<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The articles an internal link can point at, found by title for the editor's
 * link popover. A link stores the article's ID (rule 9), so a draft may be
 * linked: it renders as plain text until it is published. Only items of
 * public types are offered: a region has no page to link to.
 */
final class LinkTargets
{
    /**
     * Up to $limit live articles whose title contains $query, most recently
     * changed first; the most recent ones when $query is empty.
     *
     * @return list<array{id: string, title: string, type: string, status: string}>
     */
    public static function search(string $query, int $limit = 10): array
    {
        $needle = trim($query);
        // Only a type with pages can be linked to; a region's link could never be drawn.
        $public = array_values(array_map(
            fn (ContentType $type): string => $type->key,
            array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->public),
        ));

        return DB::table('contents')
            ->whereNull('deleted_at')
            ->whereIn('type', $public)
            ->when($needle !== '', fn ($builder) => $builder->where('title', 'ilike', '%'.addcslashes($needle, '%_\\').'%'))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'type', 'status'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'type' => (string) $row->type,
                'status' => (string) $row->status,
            ])
            ->all();
    }
}
