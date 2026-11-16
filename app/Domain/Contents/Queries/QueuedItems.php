<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Api\Cursor;
use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Teme\Contracts\Content\QueuedItem;
use Teme\Contracts\Content\QueuePage;
use Teme\Contracts\Content\QueueQuery;

/**
 * The content reader's queue (docs/plans/plugin-sockets-3.md): unpublished
 * items, draft, in review or scheduled, that one person may edit, oldest
 * first, one page at a time by an opaque cursor. Who may edit what is
 * ContentAbilities' restatement of ContentPolicy::update, type by type.
 */
final readonly class QueuedItems
{
    public const array STATUSES = ['draft', 'review', 'scheduled'];

    public const int MOST_ITEMS = 200;

    public const int MOST_TYPES = 10;

    private const string SORT = 'queue';

    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * Returns one page of the items the person may edit, with when each is
     * scheduled for, and the cursor for the next page, or null on the last.
     *
     * @throws InvalidArgumentException for a cursor this list didn't give, an unknown status, or a value outside the query's bounds
     */
    public function page(QueueQuery $query, User $person): QueuePage
    {
        $this->refuseOutOfBounds($query);
        $after = $query->after === null ? null : self::decode($query->after);

        $rows = Content::query()
            ->whereIn('contents.status', $query->statuses)
            ->whereNotNull('contents.working_revision_id')
            ->where(function (Builder $editable) use ($query, $person): void {
                // No type the person may edit leaves the group empty, and an empty OR group lists nothing.
                $editable->whereRaw('false');

                foreach ($query->types === [] ? $this->types->keys() : array_values(array_unique($query->types)) as $type) {
                    [$sql, $bindings] = ContentAbilities::forUser($person, $type)->canEditSql('contents.author_id', (int) $person->id);

                    if ($sql !== 'false') {
                        $editable->orWhere(fn (Builder $ofType) => $ofType->where('contents.type', $type)->whereRaw($sql, $bindings));
                    }
                }
            })
            ->when($after !== null, fn (Builder $q) => $q->where('contents.id', '>', $after))
            ->orderBy('contents.id')
            ->limit($query->limit + 1)
            ->get();

        $more = $rows->count() > $query->limit;
        $page = $rows->take($query->limit)->values();
        $last = $page->last();

        return new QueuePage(
            $page->map(fn (Content $item): QueuedItem => new QueuedItem(
                Payloads::item($item),
                $item->scheduled_for === null ? null : Payloads::date(CarbonImmutable::parse($item->scheduled_for)),
            ))->all(),
            $more && $last instanceof Content ? Cursor::after(self::SORT, (string) $last->id, (string) $last->id) : null,
        );
    }

    private function refuseOutOfBounds(QueueQuery $query): void
    {
        if ($query->statuses === [] || array_diff($query->statuses, self::STATUSES) !== []) {
            throw new InvalidArgumentException('A queue lists items by draft, review or scheduled.');
        }

        if ($query->limit < 1 || $query->limit > self::MOST_ITEMS) {
            throw new InvalidArgumentException('A page lists 1 to '.self::MOST_ITEMS.' items.');
        }

        if (count($query->types) > self::MOST_TYPES) {
            throw new InvalidArgumentException('A query names at most '.self::MOST_TYPES.' types.');
        }
    }

    /**
     * The ID a cursor names.
     *
     * @throws InvalidArgumentException for anything this list couldn't have given
     */
    private static function decode(string $cursor): string
    {
        $position = Cursor::read($cursor, self::SORT);

        if ($position === null || $position->key !== $position->id) {
            throw new InvalidArgumentException('That cursor wasn’t given by this list.');
        }

        return $position->id;
    }
}
