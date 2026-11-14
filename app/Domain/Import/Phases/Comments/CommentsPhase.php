<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Comments;

use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentPlace;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\ImportComment;
use App\Domain\Comments\Operations\Input\ImportCommentInput;
use App\Domain\Comments\Operations\Input\UpdateImportedCommentInput;
use App\Domain\Comments\Operations\UpdateImportedComment;
use App\Domain\Contents\Models\Content;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\Terms\WordPressText;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Queries\ImportSources;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The comments phase (I-341): each WordPress comment, pingback and
 * trackback whose post or page came across becomes a Teme comment on it,
 * read in WordPress's ID order so a parent comes before its replies; a
 * later run follows WordPress's changes until someone changes the comment
 * here.
 */
final readonly class CommentsPhase implements ListPhase
{
    /** The deepest a reply goes: past it, a path passes the thread index's largest entry (I-272). */
    public const int DEEPEST = 50;

    /** WordPress's comment types that come across, and what each is here (I-277). */
    private const array KINDS = ['comment' => CommentKind::Comment, 'pingback' => CommentKind::Pingback, 'trackback' => CommentKind::Trackback];

    /** WordPress's comment statuses that come across, and what each is here (docs/studies/import.md). */
    private const array STATUSES = [
        '0' => CommentStatus::PENDING,
        '1' => CommentStatus::APPROVED,
        'spam' => CommentStatus::SPAM,
        'trash' => CommentStatus::TRASH,
        'post-trashed' => CommentStatus::TRASH,
    ];

    public function __construct(private ImportMap $map, private ImportSources $sources, private CommentWords $words) {}

    public function key(): string
    {
        return 'comments';
    }

    public function order(): int
    {
        return 700;
    }

    public function label(): string
    {
        return 'Comments';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        // In WordPress's ID order, 500 a page, so a parent is met before its replies.
        return [new ImportList('comments', [], 500)];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('comment', (int) $item['id']);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $type = mb_substr((string) ($item['type'] ?? ''), 0, 40);
        $wordpressStatus = mb_substr((string) ($item['status'] ?? ''), 0, 40);
        $kind = self::KINDS[$type] ?? null;
        $status = self::STATUSES[$wordpressStatus] ?? null;

        if ($kind === null) {
            return ImportResult::skipped(new ImportMessage('comment.other-type', "WordPress kept it as a {$type}, which isn’t a reader’s comment, a pingback or a trackback, so it wasn’t imported.", ['type' => $type]));
        }

        if ($status === null) {
            return ImportResult::skipped(new ImportMessage('comment.other-status', "WordPress gives it the status {$wordpressStatus}, which no comment has on Teme, so it wasn’t imported.", ['status' => $wordpressStatus]));
        }

        $entry = $this->map->entry($context->sourceId, $ref);

        if ($entry !== null && $entry->teme_id !== null) {
            return $this->again($entry, $item, $status, $context);
        }

        // A row with no comment yet: an earlier attempt failed, so this is its retry.
        return $this->first($ref, $item, $kind, $status, $context, retry: $entry !== null);
    }

    /**
     * A comment met for the first time: its item, its place, its date, its
     * words and its writer, then ImportComment.
     *
     * @param  array<string, mixed>  $item
     */
    private function first(SourceRef $ref, array $item, CommentKind $kind, string $status, ImportContext $context, bool $retry): ImportResult
    {
        $postId = (int) ($item['post_id'] ?? 0);
        $post = $this->map->entry($context->sourceId, SourceRef::of('post', $postId));

        if ($post === null || $post->teme_id === null) {
            // The posts phase means to try it again: wait for it.
            if ($post !== null && in_array($post->status, [ImportStatus::Failed, ImportStatus::NeedsUpdate], true)) {
                throw new ImportFailed(new ImportMessage('comment.item-failed', "Its post or page (WordPress ID {$postId}) couldn’t be imported, so it waits for it: a full import tries both again.", ['item' => $postId]));
            }

            return ImportResult::skipped($this->map->entry($context->sourceId, SourceRef::of('attachment', $postId)) !== null
                ? new ImportMessage('comment.on-media', "It’s on a media file’s page (WordPress ID {$postId}), and Teme’s media files take no comments, so it wasn’t imported.", ['item' => $postId])
                : new ImportMessage('comment.item-missing', "Its post or page (WordPress ID {$postId}) isn’t one this import brings, so neither is it.", ['item' => $postId]));
        }

        $content = Content::withTrashed()->find($post->teme_id);

        if ($content === null) {
            return ImportResult::skipped(new ImportMessage('comment.item-deleted-here', 'Its post or page was deleted on Teme after an earlier import, so it wasn’t imported.', ['item' => $postId]));
        }

        [$parentId, $warnings] = $this->place($context->sourceId, (int) $ref->id, (int) ($item['parent'] ?? 0), (string) $content->id, $retry);
        [$writtenAt, $dated] = self::writtenAt($item['date_gmt'] ?? null, $content);
        $words = $this->words->read((string) ($item['content'] ?? ''), $this->site($context));
        $writer = $this->writer($item, $context);

        $written = app(ImportComment::class)->execute(new ImportCommentInput(
            contentId: (string) $content->id,
            parentId: $parentId,
            kind: $kind,
            status: $status,
            writtenAt: $writtenAt,
            legacyId: (int) $ref->id,
            authorName: $writer['name'],
            authorEmail: $writer['email'],
            authorUrl: $writer['url'],
            authorUserId: $writer['userId'],
            body: $words['document'],
            ip: is_string($item['author_ip'] ?? null) ? $item['author_ip'] : null,
            userAgent: is_string($item['user_agent'] ?? null) ? $item['user_agent'] : null,
            actorId: $context->actorId(),
        ));

        if (! $dated) {
            $warnings[] = new ImportMessage('comment.no-date', 'WordPress had no date for it, so it’s dated when its post or page was published.');
        }

        if (! $written['legacyKept']) {
            $warnings[] = new ImportMessage('comment.anchor-taken', "Another comment on Teme already has its WordPress number, so old links to #comment-{$ref->id} won’t find it.", ['id' => (int) $ref->id]);
        }

        if ($words['message'] !== null) {
            $warnings[] = $words['message'];
        }

        $result = ImportResult::created('comment', $written['commentId'])->withFacts(self::facts($status, $written['editedAt']));

        foreach ($warnings as $warning) {
            $result = $result->withWarning($warning);
        }

        return $result;
    }

    /**
     * Where the comment goes (I-272): under its parent's Teme comment on the
     * same item, beside it past DEEPEST, or at the top with the reason. A parent that failed, or comes later in
     * WordPress's order, fails this comment so the next run places it; on
     * this comment's own retry the parent counts as missing, so no reply
     * waits for ever.
     *
     * @return array{0: string|null, 1: list<ImportMessage>}
     */
    private function place(string $sourceId, int $id, int $parent, string $contentId, bool $retry): array
    {
        if ($parent <= 0) {
            return [null, []];
        }

        $entry = $this->map->entry($sourceId, SourceRef::of('comment', $parent));
        $row = $entry !== null && $entry->teme_id !== null
            ? Comment::query()->find($entry->teme_id, ['id', 'content_id', 'path', 'depth'])
            : null;

        if ($row !== null && $row->content_id !== $contentId) {
            return [null, [new ImportMessage('comment.parent-elsewhere', "The comment it replied to (WordPress ID {$parent}) is on another post or page on Teme, so it starts a conversation of its own.", ['parent' => $parent])]];
        }

        if ($row !== null && $row->depth >= self::DEEPEST) {
            return [CommentPlace::under($row, self::DEEPEST)[0], [new ImportMessage('comment.too-deep', 'It replies more than 50 levels deep into its thread, so it sits beside the comment it answered, not under it.')]];
        }

        if ($row !== null) {
            return [(string) $row->id, []];
        }

        if (! $retry && $entry !== null && in_array($entry->status, [ImportStatus::Failed, ImportStatus::NeedsUpdate], true)) {
            throw new ImportFailed(new ImportMessage('comment.parent-failed', "The comment it replies to (WordPress ID {$parent}) couldn’t be imported, so it waits for it: the next import tries both again.", ['parent' => $parent]));
        }

        if (! $retry && $entry === null && $parent > $id) {
            throw new ImportFailed(new ImportMessage('comment.parent-later', "It replies to a comment that comes later in WordPress’s order (WordPress ID {$parent}), so the next import places it under that one.", ['parent' => $parent]));
        }

        return [null, [new ImportMessage('comment.parent-missing', "The comment it replied to (WordPress ID {$parent}) wasn’t imported, so it starts a conversation of its own.", ['parent' => $parent])]];
    }

    /**
     * A comment an earlier run made, met again because WordPress's copy
     * changed: brought up to date while it is as the import left it here.
     *
     * @param  array<string, mixed>  $item
     */
    private function again(ImportMapEntry $entry, array $item, string $status, ImportContext $context): ImportResult
    {
        $commentId = (string) $entry->teme_id;

        if (! Comment::query()->whereKey($commentId)->exists()) {
            return ImportResult::skipped(new ImportMessage('comment.deleted-here', 'It was deleted on Teme after an earlier import, so it was left deleted.'));
        }

        // Skipped keeps its Teme ID and facts, so a later change on WordPress is met here again.
        $changedHere = ImportResult::skipped(new ImportMessage('comment.changed-here', 'It was changed on Teme after an earlier import, so WordPress’s later changes to it weren’t brought across.'));
        $expectedStatus = $entry->facts['status'] ?? null;
        $expectedEdited = $entry->facts['edited'] ?? null;

        if (! is_string($expectedStatus) || ! is_string($expectedEdited)) {
            return $changedHere;
        }

        $words = $this->words->read((string) ($item['content'] ?? ''), $this->site($context));
        $writer = $this->writer($item, $context);
        $answer = app(UpdateImportedComment::class)->execute(new UpdateImportedCommentInput(
            commentId: $commentId,
            expectedStatus: $expectedStatus,
            expectedEditedAt: CarbonImmutable::parse($expectedEdited),
            status: $status,
            authorName: $writer['name'],
            authorEmail: $writer['email'],
            authorUrl: $writer['url'],
            authorUserId: $writer['userId'],
            body: $words['document'],
            actorId: $context->actorId(),
        ));

        if ($answer['changedHere']) {
            return $changedHere;
        }

        $result = ImportResult::updated('comment', $commentId)->withFacts(self::facts($status, $answer['editedAt']));

        return $words['message'] === null ? $result : $result->withWarning($words['message']);
    }

    /**
     * The writer as Teme keeps them (I-270): the name decoded once, the
     * email address lower-cased, a web address as their website, and the
     * person their WordPress account became, when the import brought them
     * (a subscriber's comment stays a guest's).
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{name: string|null, email: string|null, url: string|null, userId: int|null}
     */
    private function writer(array $item, ImportContext $context): array
    {
        $name = mb_substr(WordPressText::decoded($item['author_name'] ?? ''), 0, 255);
        $email = Str::lower(trim((string) ($item['author_email'] ?? '')));
        $url = trim((string) ($item['author_url'] ?? ''));
        $account = (int) ($item['user_id'] ?? 0);
        $person = $account > 0 ? $this->map->temeId($context->sourceId, SourceRef::of('user', $account)) : null;

        return [
            'name' => $name === '' ? null : $name,
            'email' => $email === '' || mb_strlen($email) > 254 ? null : $email,
            'url' => preg_match('#^https?://\S+$#i', $url) === 1 && mb_strlen($url) <= 200 ? $url : null,
            'userId' => $person !== null && User::query()->whereKey($person)->exists() ? (int) $person : null,
        ];
    }

    /**
     * When WordPress says it was written, in UTC; or, when it kept no date,
     * when its item was published, and false.
     *
     * @return array{0: CarbonImmutable, 1: bool}
     */
    private static function writtenAt(mixed $dateGmt, Content $content): array
    {
        if (is_string($dateGmt) && preg_match('/^(\d{4})-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateGmt, $date) === 1 && (int) $date[1] >= 1970) {
            $written = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateGmt, 'UTC');

            if ($written instanceof CarbonImmutable) {
                return [$written, true];
            }
        }

        // Larastan reads published_at as a string, so it's parsed here.
        return [CarbonImmutable::parse((string) ($content->published_at ?? $content->created_at))->utc(), false];
    }

    /**
     * The old site's own addresses, so its links are told from others'.
     */
    private function site(ImportContext $context): ConversionSite
    {
        return new ConversionSite((string) $this->sources->find($context->sourceId)?->address);
    }

    /**
     * What the map keeps of the comment as the import last wrote it (I-341):
     * its status and its updated_at, which a later run compares.
     *
     * @return array{status: string, edited: string}
     */
    private static function facts(string $status, CarbonImmutable $editedAt): array
    {
        return ['status' => $status, 'edited' => $editedAt->utc()->toIso8601String()];
    }
}
