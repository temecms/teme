<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaUrls;
use App\Domain\Support\Queries\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The files an editor can choose from the media modal: live, ready, of an
 * allowed kind, newest first, paged by keyset and searchable — the same row
 * shape MediaLibrary uses, so the modal's grid and the Media screen's share
 * one component. Never a trashed or still-processing item.
 */
final class MediaPicker
{
    private const int PAGE = 30;

    /**
     * One page of the picker the request describes, restricted to $kinds,
     * and the cursors either side of it. No tabs: the modal does not show
     * counts.
     *
     * @param  list<string>  $kinds
     *
     * @return array{rows: list<array<string, mixed>>, newer: string|null, older: string|null}
     */
    public function page(MediaLibraryRequest $request, array $kinds): array
    {
        $direction = $request->direction === 'asc' ? 'asc' : 'desc';
        $backwards = $request->before !== null && $request->before !== '';
        $cursor = Cursor::decode($backwards ? $request->before : $request->after, 2);
        $scan = $backwards ? ($direction === 'asc' ? 'desc' : 'asc') : $direction;

        $query = DB::table('media')
            ->leftJoin('users', 'users.id', '=', 'media.uploaded_by')
            ->whereNull('media.deleted_at')
            ->where('media.processing', 'ready')
            ->whereIn('media.kind', $kinds)
            ->select([
                'media.id', 'media.kind', 'media.title', 'media.original_filename', 'media.extension', 'media.width', 'media.height',
                'media.size_bytes', 'media.variants', 'media.working_uses', 'media.published_uses', 'media.alt', 'media.credit', 'media.licence',
                'media.created_at', 'users.name as uploader_name',
            ]);

        $this->applyFilters($query, $request);

        if ($cursor !== null) {
            $query->whereRaw('(media.created_at, media.id) '.($scan === 'asc' ? '>' : '<').' (?, ?)', $cursor);
        }

        $rows = $query->orderBy('media.created_at', $scan)->orderBy('media.id', $scan)->limit(self::PAGE + 1)->get();
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
                'processing' => 'ready',
                'uses' => (int) $row->working_uses + (int) $row->published_uses,
                'hasAlt' => trim((string) $row->alt) !== '',
                'alt' => $row->alt === null ? null : (string) $row->alt,
                'credit' => $row->credit === null ? null : (string) $row->credit,
                'licence' => $row->licence === null ? null : (string) $row->licence,
                'trashed' => false,
                'createdAt' => $row->created_at,
                'uploaderName' => $row->uploader_name,
            ])->values()->all(),
            'newer' => $first !== null && ($backwards ? $hasMore : $cursor !== null) ? Cursor::encode([$first->created_at, $first->id]) : null,
            'older' => $last !== null && ($backwards ? true : $hasMore) ? Cursor::encode([$last->created_at, $last->id]) : null,
        ];
    }

    private function applyFilters(Builder $query, MediaLibraryRequest $request): void
    {
        if ($request->search !== null && trim($request->search) !== '') {
            $query->whereRaw(
                'media_search_text(media.original_filename, media.title, media.alt, media.caption) LIKE ?',
                ['%'.addcslashes(mb_strtolower(trim($request->search)), '%_\\').'%'],
            );
        }

        if ($request->unused) {
            $query->whereRaw('media.working_uses = 0 AND media.published_uses = 0');
        }

        if ($request->missingAlt) {
            $query->whereRaw("media.kind = 'image' AND (media.alt IS NULL OR media.alt = '')");
        }

        $month = $request->monthBounds();

        if ($month !== null) {
            $query->where('media.created_at', '>=', $month[0])->where('media.created_at', '<', $month[1]);
        }

        if ($request->item() !== null) {
            $query->where('media.uploaded_for', $request->item());
        }
    }
}
