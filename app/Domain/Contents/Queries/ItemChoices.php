<?php

/**
 * The posts and pages a picker offers for a search: the live ones and the
 * drafts the person may update, whose title holds what was typed, at most six
 * shown with the total. It reads only, and never lists an item the person
 * can't open.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\CoreTypes;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\Gate;

final class ItemChoices
{
    /** The most items shown at once. @since next */
    public const int SHOWN = 6;

    /** The most candidates one search reads. @since next */
    public const int CANDIDATES = 200;

    /**
     * A type's name in the admin's language, as the picker shows it beside an item.
     *
     * @since next
     */
    public static function kindLabel(string $type): string
    {
        foreach (app(ContentTypeRegistry::class)->all() as $contentType) {
            if ($contentType->key === $type) {
                return $contentType->singular;
            }
        }

        return $type;
    }

    /**
     * The first SHOWN items whose title holds the words typed, that the person
     * may open, with how many in all; nothing when nothing is typed.
     *
     * @return array{shown: list<array{id: string, title: string, kind: string, status: string, date: string}>, total: int}
     *
     * @since next
     */
    public static function search(string $query, string $personId): array
    {
        $needle = trim($query);
        $person = app(Actors::class)->find($personId);

        if ($needle === '' || $person === null) {
            return ['shown' => [], 'total' => 0];
        }

        $readable = Content::query()
            ->whereNull('deleted_at')
            ->whereIn('type', [CoreTypes::POST, CoreTypes::PAGE])
            ->where('title', 'ilike', '%'.addcslashes($needle, '%_\\').'%')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (Content $item): bool => $item->status === 'published' || Gate::forUser($person)->allows('update', $item))
            ->values();

        return [
            'shown' => $readable->take(self::SHOWN)->map(fn (Content $item): array => [
                'id' => (string) $item->id,
                'title' => (string) $item->title,
                'kind' => self::kindLabel((string) $item->type),
                'status' => (string) $item->status,
                'date' => ($item->published_at ?? $item->updated_at)->toIso8601String(),
            ])->all(),
            'total' => $readable->count(),
        ];
    }
}
