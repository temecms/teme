<?php

/**
 * The comments waiting for approval on the items a person looks after
 * (1.8c's D19, D20): the Comments menu entry's badge, its first provider,
 * and Home's figure. Summed from comment_stats through comment_stats_waiting,
 * so only items with a comment waiting are read, items in the trash left out.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Navigation\BadgeProvider;
use App\Domain\Settings\Modules;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CommentsWaiting implements BadgeProvider
{
    /**
     * How many comments wait for approval on the items the person looks
     * after; 0 while comments are switched off, or for someone who looks
     * after none.
     */
    public function countFor(User $user): int
    {
        if (! Modules::on(Modules::COMMENTS)) {
            return 0;
        }

        $scope = CommentScope::forViewer($user);

        if (! $scope->any()) {
            return 0;
        }

        $query = DB::table('comment_stats')
            ->join('contents', 'contents.id', '=', 'comment_stats.content_id')
            ->where('comment_stats.pending_count', '>', 0)
            ->whereNull('contents.deleted_at');

        $scope->constrain($query);

        return (int) $query->sum('comment_stats.pending_count');
    }
}
