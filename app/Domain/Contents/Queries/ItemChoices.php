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
use App\Domain\Contents\Visibility;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\CoreTypes;
use App\Domain\Users\Actors;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ItemChoices
{
    /** The most items shown at once. @since next */
    public const int SHOWN = 6;

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
     * The posts and pages the person may read: published and public, or any
     * item (draft, private or protected) the person may edit. Its rules are
     * the rules of the update gate, applied in the query.
     *
     * @return Builder<Content>
     *
     * @since next
     */
    public static function readable(User $person): Builder
    {
        $registry = app(ContentTypeRegistry::class);
        $query = Content::query()->whereNull('deleted_at')->whereIn('type', [CoreTypes::POST, CoreTypes::PAGE]);

        return $query->where(function (Builder $readable) use ($person, $registry): void {
            $readable->where(fn (Builder $live): Builder => $live->where('status', 'published')->where('visibility', Visibility::Public->value));

            foreach ([CoreTypes::POST, CoreTypes::PAGE] as $type) {
                if ($person->can($registry->ability($type, 'edit.any'))) {
                    $readable->orWhere('type', $type);

                    continue;
                }

                if ($person->can($registry->ability($type, 'edit.own'))) {
                    $readable->orWhere(fn (Builder $own): Builder => $own->where('type', $type)->where('author_id', $person->getKey()));
                }
            }
        });
    }

    /**
     * The first SHOWN items whose title holds the words typed, that the person
     * may read, with how many in all; nothing when nothing is typed.
     *
     * @return array{shown: list<array{id: string, title: string, kind: string, status: string, date: string, categories: list<string>}>, total: int}
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

        $matches = self::readable($person)->where('title', 'ilike', '%'.addcslashes($needle, '%_\\').'%');
        $items = (clone $matches)->orderByDesc('updated_at')->orderByDesc('id')->limit(self::SHOWN)->get();

        $categories = self::categoriesOf($items->pluck('id')->all());

        return [
            'shown' => $items->map(fn (Content $item): array => [
                'id' => (string) $item->id,
                'title' => (string) $item->title,
                'kind' => self::kindLabel((string) $item->type),
                'status' => (string) $item->status,
                'date' => ($item->published_at ?? $item->updated_at)->toIso8601String(),
                'categories' => $item->type === CoreTypes::POST ? ($categories[(string) $item->id] ?? []) : [],
            ])->values()->all(),
            'total' => $matches->count(),
        ];
    }

    /**
     * The category names of these items, in their order, read in one query.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, list<string>>
     *
     * @since next
     */
    private static function categoriesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $categories = [];

        foreach (DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->whereIn('content_terms.content_id', $ids)
            ->where('content_terms.scope', 'working')
            ->where('terms.taxonomy', 'category')
            ->orderBy('content_terms.position')
            ->get(['content_terms.content_id', 'terms.name']) as $row) {
            $categories[(string) $row->content_id][] = (string) $row->name;
        }

        return $categories;
    }

    /**
     * The IDs among these that are posts or pages the person may read now.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     *
     * @since next
     */
    public static function readableIds(array $ids, User $person): array
    {
        if ($ids === []) {
            return [];
        }

        return self::readable($person)->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
    }
}
