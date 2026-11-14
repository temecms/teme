<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaCounts;
use App\Domain\Media\MediaUrls;
use App\Domain\Support\Queries\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every media library list: one page, paged by keyset in either direction so
 * every page costs the same, with the tab counts from media_counts. Two
 * tabs, All and Trash; a kind is a filter. Filters
 * and search show no total (docs/architecture/media.md, "The library").
 */
final class MediaLibrary
{
    private const int PAGE = 60;

    /** @var array<string, string> */
    private const array TABS = ['all' => 'All', 'trash' => 'Trash'];

    /**
     * One page of the library the request describes, the cursors either side
     * of it, the tabs with their counts, and whether a filter narrowed it.
     *
     * @return array{rows: list<array<string, mixed>>, newer: string|null, older: string|null, tabs: list<array{key: string, label: string, count: int}>, filtered: bool}
     */
    public function page(MediaLibraryRequest $request): array
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'all';
        $column = $tab === 'trash' ? 'deleted_at' : 'created_at';
        $direction = $tab === 'trash' ? 'desc' : ($request->direction === 'asc' ? 'asc' : 'desc');

        // A before that does not decode (a hand-edited link) is no before: page one.
        $before = Cursor::decode($request->before, 2);
        $backwards = $before !== null;
        $cursor = $before ?? Cursor::decode($request->after, 2);
        $scan = $backwards ? ($direction === 'asc' ? 'desc' : 'asc') : $direction;

        $query = DB::table('media')
            ->leftJoin('users', 'users.id', '=', 'media.uploaded_by')
            ->select([
                'media.id', 'media.kind', 'media.title', 'media.original_filename', 'media.extension', 'media.width', 'media.height',
                'media.size_bytes', 'media.variants', 'media.processing', 'media.working_uses', 'media.published_uses', 'media.alt', 'media.credit', 'media.licence',
                'media.created_at', 'media.deleted_at', 'users.name as uploader_name',
            ]);

        if ($tab === 'trash') {
            $query->whereNotNull('media.deleted_at');
        } else {
            $query->whereNull('media.deleted_at');
        }

        $filtered = $this->applyFilters($query, $request);

        if ($cursor !== null) {
            $query->whereRaw('(media.'.$column.', media.id) '.($scan === 'asc' ? '>' : '<').' (?, ?)', $cursor);
        }

        $rows = $query->orderBy("media.{$column}", $scan)->orderBy('media.id', $scan)->limit(self::PAGE + 1)->get();
        $hasMore = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $rows->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'title' => (string) $row->title,
                'filename' => (string) $row->original_filename,
                'extension' => (string) $row->extension,
                'width' => $row->width === null ? null : (int) $row->width,
                'height' => $row->height === null ? null : (int) $row->height,
                'sizeBytes' => (int) $row->size_bytes,
                'thumbUrl' => MediaUrls::variant((array) json_decode((string) $row->variants, true)),
                'processing' => (string) $row->processing,
                'uses' => (int) $row->working_uses + (int) $row->published_uses,
                'hasAlt' => trim((string) $row->alt) !== '',
                'alt' => $row->alt === null ? null : (string) $row->alt,
                'credit' => $row->credit === null ? null : (string) $row->credit,
                'licence' => $row->licence === null ? null : (string) $row->licence,
                'trashed' => $row->deleted_at !== null,
                'createdAt' => $row->created_at,
                'uploaderName' => $row->uploader_name,
            ])->values()->all(),
            'newer' => $first !== null && ($backwards ? $hasMore : $cursor !== null) ? Cursor::encode([$first->{$column}, $first->id]) : null,
            'older' => $last !== null && ($backwards ? true : $hasMore) ? Cursor::encode([$last->{$column}, $last->id]) : null,
            'tabs' => $this->tabs(),
            'filtered' => $filtered,
        ];
    }

    private function applyFilters(Builder $query, MediaLibraryRequest $request): bool
    {
        $filtered = false;

        if ($request->kind() !== null) {
            $query->where('media.kind', $request->kind());
            $filtered = true;
        }

        if ($request->search !== null && trim($request->search) !== '') {
            $query->whereRaw(
                'media_search_text(media.original_filename, media.title, media.alt, media.caption) LIKE ?',
                ['%'.addcslashes(mb_strtolower(trim($request->search)), '%_\\').'%'],
            );
            $filtered = true;
        }

        if ($request->mine) {
            $query->where('media.uploaded_by', $request->viewerId);
            $filtered = true;
        }

        if ($request->unused) {
            // Literal, so the condition matches the partial index exactly.
            $query->whereRaw('media.working_uses = 0 AND media.published_uses = 0');
            $filtered = true;
        }

        if ($request->missingAlt) {
            $query->whereRaw("media.kind = 'image' AND (media.alt IS NULL OR media.alt = '')");
            $filtered = true;
        }

        $month = $request->monthBounds();

        if ($month !== null) {
            $query->where('media.created_at', '>=', $month[0])->where('media.created_at', '<', $month[1]);
            $filtered = true;
        }

        if ($request->item() !== null) {
            // An item's own files, through media_uploaded_for; they are few, so their order costs a small sort (I-553).
            $query->where('media.uploaded_for', $request->item());
            $filtered = true;
        }

        // Later (I-172): tags or collections, if search and filters prove not enough.
        return $filtered;
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function tabs(): array
    {
        $counts = MediaCounts::all();
        $sum = fn (string $bucket): int => array_sum(array_map(fn (array $buckets): int => $buckets[$bucket] ?? 0, $counts));

        return [
            ['key' => 'all', 'label' => __(self::TABS['all']), 'count' => $sum('live')],
            ['key' => 'trash', 'label' => __(self::TABS['trash']), 'count' => $sum('trash')],
        ];
    }
}
