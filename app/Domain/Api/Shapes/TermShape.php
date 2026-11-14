<?php

declare(strict_types=1);

namespace App\Domain\Api\Shapes;

use App\Domain\Addresses\Addresses;

/**
 * A term as the API gives it: on an item, its ID, taxonomy, name, slug and
 * address (D6); on its own, also its description, parent and count (D27).
 * Built from a `terms` row and its current address, read by the caller.
 */
final class TermShape
{
    /**
     * The term as an item carries it.
     *
     * @param  object{id: string, taxonomy: string, name: string, slug: string}  $row
     * @param  string|null  $path  its current address as `paths` stores it, or null for none
     *
     * @return array{id: string, taxonomy: string, name: string, slug: string, path: string|null, url: string|null}
     */
    public static function link(object $row, ?string $path): array
    {
        $relative = $path === null ? null : Addresses::toUrl($path);

        return [
            'id' => (string) $row->id,
            'taxonomy' => (string) $row->taxonomy,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'path' => $relative,
            'url' => $relative === null ? null : rtrim(url('/'), '/').$relative,
        ];
    }

    /**
     * The term on its own, without its links, which the answer adds.
     *
     * @param  object{id: string, taxonomy: string, name: string, slug: string, description: string|null, parent_id: string|null}  $row
     * @param  string|null  $path  its current address as `paths` stores it, or null for none
     *
     * @return array<string, mixed>
     */
    public static function full(object $row, ?string $path, int $count): array
    {
        $description = trim((string) ($row->description ?? ''));

        return [
            ...self::link($row, $path),
            'description' => $description === '' ? null : $description,
            'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
            'count' => $count,
        ];
    }
}
