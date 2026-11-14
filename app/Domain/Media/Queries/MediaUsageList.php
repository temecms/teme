<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Contents\Queries\PrivateTitles;
use App\Domain\Support\Queries\Cursor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where one media item is used: the content items whose working or published
 * revision refers to it, one row each, paged by content ID on the
 * (media_id, content_id, scope) index. A private item's title is shown only
 * to whoever may edit it (I-1700).
 */
final class MediaUsageList
{
    private const int PAGE = 20;

    /**
     * One page of the content using the item, whether each is live on the
     * public site, and the cursor for the next page. A viewer of none sees
     * no private item's title.
     *
     * @return array{rows: list<array{id: string, title: string, type: string, status: string, published: bool}>, older: string|null}
     */
    public function page(string $mediaId, ?string $after, ?User $viewer = null): array
    {
        $cursor = Cursor::decode($after, 1);

        $uses = DB::table('media_usages')
            ->where('media_id', $mediaId)
            ->when($cursor !== null, fn ($query) => $query->where('content_id', '>', $cursor[0]))
            ->groupBy('content_id')
            ->orderBy('content_id')
            ->limit(self::PAGE + 1)
            ->selectRaw("content_id, bool_or(scope = 'published') AS published")
            ->get();

        $more = $uses->count() > self::PAGE;
        $uses = $uses->take(self::PAGE);
        $contents = DB::table('contents')->whereIn('id', $uses->pluck('content_id')->all())->get(['id', 'title', 'type', 'status', 'author_id', 'visibility'])->keyBy('id');

        return [
            'rows' => $uses->map(fn (object $use): array => [
                'id' => (string) $use->content_id,
                'title' => $this->title($contents[$use->content_id] ?? null, $viewer),
                'type' => (string) ($contents[$use->content_id]->type ?? ''),
                'status' => (string) ($contents[$use->content_id]->status ?? ''),
                'published' => (bool) $use->published,
            ])->values()->all(),
            'older' => $more ? Cursor::encode([(string) $uses->last()->content_id]) : null,
        ];
    }

    private function title(?object $content, ?User $viewer): string
    {
        if ($content === null) {
            return '';
        }

        return PrivateTitles::shown((string) $content->title, (string) $content->type, $content->author_id === null ? null : (int) $content->author_id, (string) $content->visibility, $viewer);
    }
}
