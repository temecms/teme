<?php

/**
 * The site's figures on the Home screen, from the maintained counts —
 * content_counts' all-authors status rows and media_counts — never by counting
 * rows: each public type's published, drafts and scheduled, then the comments
 * waiting on the items the viewer looks after (1.8c), then media.
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
    public function __construct(private ContentTypeRegistry $types, private CommentsWaiting $waiting) {}

    /**
     * The figures in order, each with its group heading (the type's plural,
     * or none) and its list's address where the viewer may open it.
     *
     * @return list<array{key: string, label: string, group: string|null, value: int, url: string|null}>
     */
    public function forUser(User $viewer): array
    {
        $public = array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->public));
        $counts = DB::table('content_counts')
            ->where('author_id', 0)
            ->whereIn('bucket', ['published', 'draft', 'scheduled'])
            ->whereIn('type', array_map(fn (ContentType $type): string => $type->key, $public))
            ->get(['type', 'bucket', 'count']);

        $value = fn (string $type, string $bucket): int => (int) ($counts->first(fn (object $row): bool => $row->type === $type && $row->bucket === $bucket)->count ?? 0);
        $figures = [];

        foreach ($public as $type) {
            foreach (['published' => ['published', 'Published', 'published'], 'drafts' => ['draft', 'Drafts', 'drafts'], 'scheduled' => ['scheduled', 'Scheduled', 'scheduled']] as $key => [$bucket, $label, $tab]) {
                $figures[] = [
                    'key' => "{$type->key}.{$key}",
                    'label' => $label,
                    'group' => $type->plural,
                    'value' => $value($type->key, $bucket),
                    'url' => $type->listed && $viewer->can($type->ability('create')) ? route('content.index', ['type' => $type->segment, 'tab' => $tab]) : null,
                ];
            }
        }

        // The same figure as the menu's badge (C2): C2's words by who looks after what.
        $scope = Modules::on(Modules::COMMENTS) ? CommentScope::forViewer($viewer) : null;

        if ($scope?->any()) {
            $figures[] = [
                'key' => 'comments.waiting',
                'label' => $scope->everyItem ? 'Comments waiting' : 'Comments waiting on your posts and pages',
                'group' => null,
                'value' => $this->waiting->countFor($viewer),
                'url' => route('comments.index', ['view' => 'pending']),
            ];
        }

        $figures[] = [
            'key' => 'media',
            'label' => 'Media',
            'group' => null,
            'value' => (int) DB::table('media_counts')->where('bucket', 'live')->sum('count'),
            'url' => $viewer->can('media.upload') ? route('media.index') : null,
        ];

        return $figures;
    }
}
