<?php

/**
 * One comment's drawer on the Comments screen (1.8c's D13, D14, D16), or why
 * it can't open.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\CommentVerdict;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CommentDetails
{
    public function __construct(
        private readonly CommentStatusRegistry $statuses,
        private readonly ContentTypeRegistry $types,
    ) {}

    /**
     * Null for a comment that doesn't exist, is a tombstone, or sits on an
     * item in the trash; `mine` false, with the item alone, for a comment on
     * an item the person doesn't look after (C7); otherwise `mine` true and
     * the details, keys in CommentDetails' order, the address and browser
     * only for whoever sees addresses (D14), with when they're erased
     * (`erasesAt`, keep_ip_days after arrival) and that number (`keptDays`,
     * 1.8d's D14).
     *
     * @return array{mine: true, details: array<string, mixed>}|array{mine: false, item: array{id: string, title: string, singular: string, owner: string|null}}|null
     */
    public function find(int $viewerId, string $commentId): ?array
    {
        $viewer = User::query()->findOrFail($viewerId);
        $scope = CommentScope::forViewer($viewer);

        $row = DB::table('comments')
            ->join('contents', 'contents.id', '=', 'comments.content_id')
            ->leftJoin('comments as parent', 'parent.id', '=', 'comments.parent_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'contents.author_id')
            ->whereNull('contents.deleted_at')
            ->where('comments.id', $commentId)
            ->select([
                'comments.id', 'comments.kind', 'comments.status', 'comments.parent_id', 'comments.author_name',
                'comments.author_email', 'comments.author_url', 'comments.author_user_id', 'comments.body',
                'comments.moderation', 'comments.legacy_id', 'comments.created_at', 'comments.content_id',
                'contents.title', 'contents.type', 'contents.author_id',
                'parent.author_name as parent_name', 'parent.status as parent_status', 'owner.name as owner',
            ])
            // An address and a browser leave the database only for those who see them (I-270 B, D14).
            ->selectRaw($scope->everyItem ? 'host(comments.ip) AS ip, comments.user_agent AS user_agent' : 'NULL AS ip, NULL AS user_agent')
            ->first();

        // A tombstone holds nothing to moderate (I-278).
        if ($row === null || $row->status === CommentStatus::REMOVED) {
            return null;
        }

        $word = $this->types->has((string) $row->type) ? mb_strtolower($this->types->get((string) $row->type)->singular) : 'item';

        $looksAfter = DB::table('contents')->where('contents.id', $row->content_id);
        $scope->constrain($looksAfter);

        if (! $looksAfter->exists()) {
            return [
                'mine' => false,
                'item' => [
                    'id' => (string) $row->content_id,
                    'title' => (string) $row->title,
                    'singular' => $word,
                    'owner' => $row->owner === null ? null : (string) $row->owner,
                ],
            ];
        }

        $body = json_decode((string) $row->body, true);
        $body = is_array($body) ? $body : [];
        $moderation = $row->moderation === null ? null : json_decode((string) $row->moderation, true);
        $parentId = $row->parent_id === null ? null : (string) $row->parent_id;
        $removed = $row->parent_status === CommentStatus::REMOVED;
        $abilities = ContentAbilities::forUser($viewer, (string) $row->type);
        $authorId = $row->author_id === null ? null : (int) $row->author_id;
        $legacyId = $row->legacy_id === null ? null : (int) $row->legacy_id;
        // When the address and browser go (D14): keep_ip_days after arrival (I-270).
        $keep = DiscussionSettings::current()->keepIpDays;
        // Every reply, whatever its status (I-577): what a permanent deletion would leave a tombstone for.
        $replies = DB::table('comments')->where('parent_id', $row->id)->count();

        return [
            'mine' => true,
            'details' => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'status' => (string) $row->status,
                'statusLabel' => $this->statuses->get((string) $row->status)->label,
                'authorName' => (string) $row->author_name,
                'authorEmail' => $row->author_email === null ? null : (string) $row->author_email,
                'authorUrl' => $row->author_url === null ? null : (string) $row->author_url,
                'signedIn' => $row->author_user_id !== null,
                'item' => [
                    'id' => (string) $row->content_id,
                    'title' => (string) $row->title,
                    'editable' => $abilities->editAny || ($abilities->editOwn && $authorId !== null && $authorId === (int) $viewer->id),
                ],
                'parent' => $parentId === null ? null : [
                    'authorName' => $removed || $row->parent_name === null ? null : (string) $row->parent_name,
                    'removed' => $removed,
                ],
                'replies' => $replies,
                'createdAt' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
                'ip' => $row->ip === null ? null : (string) $row->ip,
                'userAgent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'writtenInAdmin' => is_array($moderation) && (($moderation['signals']['written'] ?? null) === 'admin'),
                'erasesAt' => CarbonImmutable::parse((string) $row->created_at)->addDays($keep)->utc()->toIso8601String(),
                'keptDays' => $keep,
                'legacyId' => $legacyId,
                'text' => CommentText::plain($body),
                'plain' => CommentText::isPlain($body),
                'judgement' => CommentVerdict::judgement(is_array($moderation) ? $moderation : null, $body, $legacyId, (string) $row->author_name, $word),
            ],
        ];
    }
}
