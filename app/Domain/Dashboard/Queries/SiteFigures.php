<?php

/**
 * The site's five figures on the Home screen, from the maintained counts —
 * content_counts' all-authors status rows — never by counting rows: published,
 * drafts, scheduled and in review across every type the viewer may list, then
 * the comments waiting on the items the viewer looks after (1.8c).
 * A figure whose screen the viewer may not open has no link. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Comments\Queries\CommentScope;
use App\Domain\Comments\Queries\CommentsWaiting;
use App\Domain\Settings\Modules;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SiteFigures
{
    /** @var array<string, array{0: string, 1: string, 2: string}> key => [status bucket, label, dot] */
    private const array STATES = [
        'published' => ['published', 'Published', 'ok'],
        'drafts' => ['draft', 'Drafts', 'neutral-dot'],
        'scheduled' => ['scheduled', 'Scheduled', 'info'],
        'review' => ['review', 'In review', 'warn'],
    ];

    public function __construct(private ContentTypeRegistry $types, private CommentsWaiting $waiting) {}

    /**
     * The figures in order, each with its status dot and the content list's
     * address, filtered to its state, where the viewer may open it.
     *
     * @return list<array{key: string, label: string, dot: string, value: int, url: string|null}>
     */
    public function forUser(User $viewer): array
    {
        $listable = array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->listed && $viewer->can($type->ability('create'))));
        $counts = $listable === [] ? collect() : DB::table('content_counts')
            ->where('author_id', 0)
            ->whereIn('bucket', array_column(self::STATES, 0))
            ->whereIn('type', array_map(fn (ContentType $type): string => $type->key, $listable))
            ->selectRaw('bucket, sum(count) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        // The lists show one type at a time, so a figure opens the first the viewer may list, Posts on a site that keeps it.
        $posts = $listable[0] ?? null;
        $figures = [];

        foreach (self::STATES as $key => [$bucket, $label, $dot]) {
            $figures[] = [
                'key' => $key,
                'label' => $label,
                'dot' => $dot,
                'value' => (int) ($counts[$bucket] ?? 0),
                'url' => $posts === null ? null : route('content.index', ['type' => $posts->segment, 'tab' => $key]),
            ];
        }

        // The same figure as the menu's badge (C2): C2's words by who looks after what.
        $scope = Modules::on(Modules::COMMENTS) ? CommentScope::forViewer($viewer) : null;

        if ($scope?->any()) {
            $figures[] = [
                'key' => 'comments.waiting',
                'label' => $scope->everyItem ? 'Comments waiting' : 'Comments waiting on your posts and pages',
                'dot' => 'accent',
                'value' => $this->waiting->countFor($viewer),
                'url' => route('comments.index', ['view' => 'pending']),
            ];
        }

        return $figures;
    }
}
