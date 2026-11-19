<?php

/**
 * Tools › Redirects' list (I-872): a page of redirects at a time, newest
 * first, found by address, typed target or note, each with where it sends
 * readers in words, what it answers, its hits, and what it does now
 * (I-857, I-858).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Redirects\RedirectStatus;
use App\Domain\Redirects\RedirectTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type RedirectTo array{kind: string|null, address: string|null, title: string|null, contentId: string|null, mediaId: string|null}
 * @phpstan-type RedirectRow array{id: string, from: string, pattern: bool, status: int, statusLabel: string, to: RedirectTo, note: string, origin: string, hits: int, lastHitAt: string|null, on: bool, state: string, stateLabel: string}
 */
final readonly class RedirectList
{
    /** The longest search read, in characters. */
    public const int LONGEST_SEARCH = 200;

    /** The most rows a page holds. */
    private const int MOST = 200;

    /** The columns a row is drawn from. */
    private const array COLUMNS = ['id', 'source', 'kind', 'status', 'target_kind', 'target', 'note', 'origin', 'hits', 'last_hit_at', 'enabled'];

    public function __construct(private RedirectStates $states) {}

    /**
     * Returns a page of redirects, newest first, at most $perPage (1 to
     * 200), whose address as shown, typed target or note holds $search, and
     * that are switched on or off, answer with a status and came from an
     * origin, where $filters names them; older than the redirect $older
     * names, or newer than the one $newer names; with the IDs that open the
     * pages either side, or null where there is none.
     *
     * @param  array{on?: bool|null, status?: int|null, origin?: string|null}  $filters
     *
     * @return array{rows: list<RedirectRow>, newer: string|null, older: string|null}
     */
    public function page(string $search, int $perPage, ?string $older = null, ?string $newer = null, array $filters = []): array
    {
        $limit = max(1, min(self::MOST, $perPage));
        $backwards = $older === null && $newer !== null;

        $found = self::filtered(DB::table('redirects'), $search, $filters)
            ->when($older !== null, fn (Builder $query): Builder => $query->where('id', '<', $older))
            ->when($backwards, fn (Builder $query): Builder => $query->where('id', '>', $newer))
            ->orderBy('id', $backwards ? 'asc' : 'desc')
            ->limit($limit + 1)
            ->get(self::COLUMNS);

        $more = $found->count() > $limit;
        $found = $found->take($limit);
        $rows = $this->shown($backwards ? $found->reverse()->values() : $found->values());

        if ($rows === []) {
            return ['rows' => [], 'newer' => null, 'older' => null];
        }

        $first = $rows[0]['id'];
        $last = $rows[array_key_last($rows)]['id'];

        return [
            'rows' => $rows,
            'newer' => $backwards ? ($more ? $first : null) : ($older !== null ? $first : null),
            'older' => $backwards || $more ? $last : null,
        ];
    }

    /**
     * Returns one redirect as the list shows it, or null when there is none.
     *
     * @return RedirectRow|null
     */
    public function find(string $id): ?array
    {
        return $this->shown(DB::table('redirects')->where('id', $id)->get(self::COLUMNS))[0] ?? null;
    }

    /**
     * Returns how many redirects $search and $filters find, as page() finds them.
     *
     * @since next
     *
     * @param  array{on?: bool|null, status?: int|null, origin?: string|null}  $filters
     */
    public function count(string $search, array $filters = []): int
    {
        return self::filtered(DB::table('redirects'), $search, $filters)->count();
    }

    /**
     * The search and the filters, as page() and count() apply them.
     *
     * @param  array{on?: bool|null, status?: int|null, origin?: string|null}  $filters
     */
    private static function filtered(Builder $query, string $search, array $filters): Builder
    {
        $on = $filters['on'] ?? null;
        $status = $filters['status'] ?? null;
        $origin = $filters['origin'] ?? null;

        return $query
            ->when(trim($search) !== '', fn (Builder $query): Builder => self::matching($query, $search))
            ->when($on !== null, fn (Builder $query): Builder => $query->where('enabled', $on))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($origin !== null, fn (Builder $query): Builder => $query->where('origin', $origin));
    }

    /**
     * Returns how many redirects there are, and how many addresses the 404
     * log holds, for the screen's tabs.
     *
     * @return array{redirects: int, notFound: int}
     */
    public function counts(): array
    {
        return ['redirects' => DB::table('redirects')->count(), 'notFound' => DB::table('not_found_log')->count()];
    }

    /**
     * Returns those of these redirects a switch to $on would change, the
     * ones switched the other way now, by ID: what Undo switches back.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    public function changing(array $ids, bool $on): array
    {
        return DB::table('redirects')->whereIn('id', $ids)->where('enabled', ! $on)->orderBy('id')->pluck('id')->map(strval(...))->values()->all();
    }

    /**
     * The search: the address as shown, a typed target or the note holding
     * the text as written, in any case; a trailing slash, as the site
     * writes addresses, left off.
     */
    private static function matching(Builder $query, string $search): Builder
    {
        $needle = mb_substr(trim($search), 0, self::LONGEST_SEARCH);
        $needle = $needle === '/' ? $needle : rtrim($needle, '/');
        $like = '%'.addcslashes($needle, '%_\\').'%';

        return $query->where(fn (Builder $any): Builder => $any
            ->whereRaw("('/' || source) ILIKE ?", [$like])
            ->orWhere(fn (Builder $typed): Builder => $typed
                ->whereIn('target_kind', [RedirectTarget::ADDRESS, RedirectTarget::OUTSIDE])
                ->where('target', 'ilike', $like))
            ->orWhere('note', 'ilike', $like));
    }

    /**
     * The rows as the screen draws them, with their items, files and states
     * read in one statement each.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return list<RedirectRow>
     */
    private function shown(Collection $found): array
    {
        if ($found->isEmpty()) {
            return [];
        }

        $items = $this->items($found->where('target_kind', RedirectTarget::CONTENT)->pluck('target')->map(strval(...))->values()->all());
        // Not files(): the architecture tests read that name as listing a folder.
        $media = $this->mediaTargets($found->where('target_kind', RedirectTarget::MEDIA)->pluck('target')->map(strval(...))->values()->all());
        $states = $this->states->of($found->pluck('id')->map(strval(...))->values()->all());
        $rows = [];

        foreach ($found as $row) {
            $id = (string) $row->id;
            $target = $row->target === null ? '' : (string) $row->target;

            $rows[] = [
                'id' => $id,
                'from' => '/'.$row->source,
                'pattern' => $row->kind === 'pattern',
                'status' => (int) $row->status,
                'statusLabel' => RedirectStatus::from((int) $row->status)->label(),
                'to' => match ($row->target_kind) {
                    RedirectTarget::CONTENT => ['kind' => RedirectTarget::CONTENT, 'address' => $items[$target]['address'] ?? null, 'title' => $items[$target]['title'] ?? null, 'contentId' => $target, 'mediaId' => null],
                    RedirectTarget::MEDIA => ['kind' => RedirectTarget::MEDIA, 'address' => $media[$target]['address'] ?? null, 'title' => $media[$target]['title'] ?? null, 'contentId' => null, 'mediaId' => $target],
                    RedirectTarget::ADDRESS, RedirectTarget::OUTSIDE => ['kind' => (string) $row->target_kind, 'address' => $target, 'title' => null, 'contentId' => null, 'mediaId' => null],
                    default => ['kind' => null, 'address' => null, 'title' => null, 'contentId' => null, 'mediaId' => null],
                },
                'note' => (string) $row->note,
                'origin' => (string) $row->origin,
                'hits' => (int) $row->hits,
                'lastHitAt' => $row->last_hit_at === null ? null : CarbonImmutable::parse((string) $row->last_hit_at)->utc()->toIso8601String(),
                'on' => (bool) $row->enabled,
                // A row deleted between the two reads has no state; the next visit leaves it out.
                'state' => isset($states[$id]) ? $states[$id]['state']->value : '',
                'stateLabel' => isset($states[$id]) ? $states[$id]['label'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Each item's title, and its address now: `/` for the page chosen as
     * the home page, none while it has none (a draft, a trashed or an
     * unpublished item).
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{title: string, address: string|null}>
     */
    private function items(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $settings = AddressSettings::current();
        $items = [];

        $rows = DB::table('contents as c')
            // As text, as paths.target_id is, so the join reads its index rather than every address.
            ->leftJoin('paths as p', fn (JoinClause $join): JoinClause => $join->on('p.target_id', '=', DB::raw('c.id::text'))->where('p.target_type', RedirectTarget::CONTENT)->where('p.is_current', true))
            ->whereIn('c.id', $ids)
            ->get(['c.id', 'c.type', 'c.title', 'p.path']);

        foreach ($rows as $row) {
            $front = $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === (string) $row->id;

            $items[(string) $row->id] = [
                'title' => (string) $row->title,
                'address' => $front ? '/' : ($row->path === null ? null : Addresses::toUrl((string) $row->path, (string) $row->type)),
            ];
        }

        return $items;
    }

    /**
     * Each file's name, and its public address now; none while it is
     * trashed or has no public file yet.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{title: string, address: string|null}>
     */
    private function mediaTargets(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $media = [];

        foreach (Media::withTrashed()->whereIn('id', $ids)->get() as $file) {
            $media[$file->id] = [
                'title' => $file->name,
                'address' => $file->trashed() ? null : ($file->isImage() ? MediaUrls::largest($file->variants) : MediaDisks::public()->url($file->original_path)),
            ];
        }

        return $media;
    }
}
