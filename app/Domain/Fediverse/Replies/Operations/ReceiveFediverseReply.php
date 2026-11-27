<?php

/**
 * Takes a fediverse reply to one of the site's items, or to a reply on
 * one, as a comment (docs/plans/fediverse-publishing.md, D4): through the
 * comment rules a guest's goes through, the Discussion settings, the
 * bound moderator and the waiting queue, at most ten an hour from one
 * account; its words as the restricted comment document, its writer named
 * from the remote account, with no email or IP address. The reply's
 * address is kept, linking the comment to the account. Returns the
 * comment's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentPlace;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Replies\Operations\Concerns\WrittenByTheSender;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Fediverse\Replies\RemoteWords;
use App\Domain\Fediverse\Replies\ReplyTarget;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Operations\OperationInput;
use UnexpectedValueException;

final class ReceiveFediverseReply extends BaseOperation
{
    use WrittenByTheSender;

    /** Replies one remote account may send in DECAY seconds (proposal, P2). */
    public const int MAX = 10;

    public const int DECAY = 3600;

    protected function authorize(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        $this->authorizeSender($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        $remote = RemoteActor::query()->findOrFail($input->remoteActorId);
        $object = $input->object;
        $uri = $input->uri();

        if ($uri === '' || strtolower((string) parse_url($uri, PHP_URL_SCHEME)) !== 'https' || strtolower((string) parse_url($uri, PHP_URL_HOST)) !== $remote->host) {
            throw ValidationException::withMessages(['object' => 'The reply’s address is not on its writer’s server.']);
        }

        if (self::author($object) !== $remote->uri) {
            throw ValidationException::withMessages(['object' => 'The reply is attributed to someone else.']);
        }

        if (FediverseObject::query()->whereKey($uri)->exists()) {
            throw ValidationException::withMessages(['object' => 'The reply was taken already.']);
        }

        $target = is_string($object['inReplyTo'] ?? null) ? ReplyTarget::of($object['inReplyTo']) : null;

        if ($target === null) {
            throw ValidationException::withMessages(['object' => 'It answers nothing of this site’s.']);
        }

        $closed = $target->closedBecause();

        if ($closed !== null) {
            throw ValidationException::withMessages(['object' => 'Dropped: '.$closed.'.']);
        }

        if (app(RemoteWords::class)->document(is_string($object['content'] ?? null) ? $object['content'] : '') === null) {
            throw ValidationException::withMessages(['object' => 'The reply holds no words, or too many.']);
        }

        self::count($remote);
    }

    /**
     * The comment a reply would be, as a plugin's check reads it, built with no
     * writes so that the check can be asked before the save's transaction; null
     * when the reply has no target or no words.
     *
     * @param  array<string, mixed>  $object
     */
    public static function candidate(RemoteActor $remote, array $object): ?CommentCandidate
    {
        $target = is_string($object['inReplyTo'] ?? null) ? ReplyTarget::of($object['inReplyTo']) : null;
        $document = is_string($object['content'] ?? null) ? app(RemoteWords::class)->document($object['content']) : null;

        if ($target === null || $document === null) {
            return null;
        }

        return new CommentCandidate(
            contentId: $target->item->id,
            parentId: $target->parent?->id,
            text: CommentText::plain($document),
            links: CommentText::links($document),
            authorName: mb_substr(trim((string) ($remote->name ?: $remote->handle ?: $remote->uri)), 0, 100),
            authorEmail: null,
            authorUrl: self::website($remote),
            authorUserId: null,
            ip: null,
            userAgent: null,
            returning: self::returning($remote),
            itemUrl: null,
        );
    }

    /**
     * Counts one reply or edit from the account, refusing it past MAX in an
     * hour; counted in the cache store, as a guest's comments are.
     *
     * @throws ValidationException
     */
    public static function count(RemoteActor $remote): void
    {
        $key = 'comments:fediverse:'.sha1($remote->uri);

        if (RateLimiter::tooManyAttempts($key, self::MAX)) {
            throw ValidationException::withMessages(['object' => 'Dropped: the account sent too many replies in an hour.']);
        }

        RateLimiter::hit($key, self::DECAY);
    }

    protected function perform(OperationInput $input): string
    {
        /** @var FediverseReplyInput $input */
        $remote = RemoteActor::query()->findOrFail($input->remoteActorId);
        $target = ReplyTarget::of((string) $input->object['inReplyTo']) ?? throw ValidationException::withMessages(['object' => 'It answers nothing of this site’s.']);
        $item = Content::query()->lockForUpdate()->findOrFail($target->item->id);
        $document = app(RemoteWords::class)->document((string) $input->object['content']) ?? throw ValidationException::withMessages(['object' => 'The reply holds no words.']);
        $text = CommentText::plain($document);
        $name = mb_substr(trim((string) ($remote->name ?: $remote->handle ?: $remote->uri)), 0, 100);
        $url = self::website($remote);

        if ($target->parent === null) {
            [$parentId, $parentPath, $depth] = [null, '', 1];
        } else {
            $parent = Comment::query()->lockForUpdate()->findOrFail($target->parent->id);

            if ($parent->status !== CommentStatus::APPROVED || $parent->content_id !== $item->id) {
                throw ValidationException::withMessages(['object' => 'The comment it answers isn’t there to answer.']);
            }

            [$parentId, $parentPath, $depth] = CommentPlace::under($parent, DiscussionSettings::current()->threadDepth);
        }

        $moderator = app(CommentModerator::class);
        $by = $moderator->name();
        $verdict = self::known($by, $moderator->judge(new CommentCandidate(
            contentId: $item->id,
            parentId: $parentId,
            text: $text,
            links: CommentText::links($document),
            authorName: $name,
            authorEmail: null,
            authorUrl: $url,
            authorUserId: null,
            ip: null,
            userAgent: null,
            returning: self::returning($remote),
            itemUrl: Addresses::urlFor($item),
        )));

        // A plugin's check was asked before the transaction, by InboxReplies; core's judgment still applies after it.
        $verdict = CommentChecks::strictest($input->checked, $verdict) ?? $verdict;

        $id = strtolower((string) Str::ulid(now()));

        $comment = Comment::query()->create([
            'id' => $id,
            'content_id' => $item->id,
            'parent_id' => $parentId,
            'path' => $parentPath.$id.'/',
            'depth' => $depth,
            'kind' => CommentKind::Comment,
            'status' => $verdict->status,
            'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
            'author_name' => $name,
            'author_email' => null,
            'author_url' => $url,
            'author_user_id' => null,
            'body' => $document,
            'ip' => null,
            'user_agent' => null,
            'moderation' => ['by' => $by, 'status' => $verdict->status, 'reason' => $verdict->reason, 'signals' => (object) [...$verdict->signals, 'fediverse' => $remote->uri]],
        ]);

        FediverseObject::query()->create([
            'uri' => $input->uri(),
            'kind' => FediverseObject::REPLY,
            'content_id' => $item->id,
            'comment_id' => $comment->id,
            'remote_actor_id' => $remote->id,
        ]);

        CommentStats::apply($comment, null, $comment->status);

        if ($comment->status === CommentStatus::APPROVED) {
            $this->purgeAfterCommit(CommentPurges::arrived($item->id));
            CommentEmails::approved($comment, null);
        } elseif ($comment->status === CommentStatus::PENDING) {
            CommentEmails::waiting($comment, null);
        }

        $status = (string) $comment->status;
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged($id, Payloads::item($item), null, $status, null));

        return $id;
    }

    /**
     * Whom the object says wrote it: its attributedTo, as an address or an object.
     *
     * @param  array<string, mixed>  $object
     */
    public static function author(array $object): ?string
    {
        $author = $object['attributedTo'] ?? null;
        $author = is_array($author) ? ($author['id'] ?? null) : $author;

        return is_string($author) ? $author : null;
    }

    /**
     * The account's page, kept as the commenter's website when it fits.
     */
    public static function website(RemoteActor $remote): ?string
    {
        $url = $remote->profile_url ?: $remote->uri;

        return strlen($url) <= 200 && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Whether the account had a reply approved here before.
     */
    public static function returning(RemoteActor $remote): bool
    {
        return FediverseObject::query()
            ->join('comments', 'comments.id', '=', 'fediverse_objects.comment_id')
            ->where('fediverse_objects.remote_actor_id', $remote->id)
            ->where('comments.status', CommentStatus::APPROVED)
            ->exists();
    }

    /**
     * The verdict, when it names a status a moderator may give; otherwise the reply is held.
     */
    public static function known(string $by, ModerationVerdict $verdict): ModerationVerdict
    {
        if (in_array($verdict->status, [ModerationVerdict::APPROVED, ModerationVerdict::PENDING, ModerationVerdict::SPAM], true)) {
            return $verdict;
        }

        report(new UnexpectedValueException("The comment moderator [{$by}] answered [{$verdict->status}]; the reply is held."));

        return new ModerationVerdict(ModerationVerdict::PENDING, $verdict->reason, [...$verdict->signals, 'answered' => $verdict->status]);
    }
}
