<?php

/**
 * Which published item last showed a media item, for the alt-text task
 * (I-319): published uses only, so AI is told nothing a reader of the site
 * couldn't read, and no draft, which may be someone else's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText\Queries;

use Illuminate\Support\Facades\DB;

final class LatestPublishedUse
{
    /**
     * Returns the published title of the item published most recently that
     * shows this media item, or null when no published item does.
     */
    public function title(string $mediaId): ?string
    {
        $title = DB::table('media_usages as u')
            ->join('contents as c', 'c.id', '=', 'u.content_id')
            ->join('content_revisions as r', 'r.id', '=', 'c.published_revision_id')
            ->where('u.media_id', $mediaId)
            ->where('u.scope', 'published')
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.published_at')
            ->orderByDesc('c.id')
            ->value('r.title');

        return is_string($title) && trim($title) !== '' ? $title : null;
    }
}
