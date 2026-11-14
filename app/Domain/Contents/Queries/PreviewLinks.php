<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The public side of a preview link: what a token opens, if anything. It is
 * the one door into unpublished content, so everything it cannot vouch for
 * is simply not found.
 */
final class PreviewLinks
{
    /**
     * The live link for a token, with the revision it shows, and that
     * revision's and its article's IDs for the page to be built from, or
     * null when the token is wrong, the link has expired or been revoked,
     * or its article is in the trash.
     *
     * @return array{linkId: string, contentId: string, revisionId: string, title: string, document: array<mixed>, expiresAt: string}|null
     */
    public function resolve(string $token): ?array
    {
        $hash = hash('sha256', $token);

        // The time is bound from PHP, so a test's clock reaches it.
        $row = DB::table('preview_links')
            ->join('content_revisions', 'content_revisions.id', '=', 'preview_links.revision_id')
            ->join('contents', 'contents.id', '=', 'preview_links.content_id')
            ->where('preview_links.token_hash', $hash)
            ->whereNull('preview_links.revoked_at')
            ->where('preview_links.expires_at', '>', Date::now())
            // A trashed article's links stop resolving.
            ->whereNull('contents.deleted_at')
            ->first(['preview_links.id', 'preview_links.content_id', 'preview_links.revision_id', 'preview_links.token_hash', 'preview_links.expires_at', 'content_revisions.title', 'content_revisions.document']);

        if ($row === null || ! hash_equals((string) $row->token_hash, $hash)) {
            return null;
        }

        $expires = $row->expires_at instanceof DateTimeInterface ? Carbon::instance($row->expires_at) : Carbon::parse((string) $row->expires_at);

        return [
            'linkId' => (string) $row->id,
            'contentId' => (string) $row->content_id,
            'revisionId' => (string) $row->revision_id,
            'title' => (string) $row->title,
            'document' => (array) json_decode((string) $row->document, true),
            'expiresAt' => $expires->toIso8601String(),
        ];
    }
}
