<?php

declare(strict_types=1);

namespace App\Domain\Comments\Privacy;

use App\Domain\Cache\PublicChanges;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\CommentText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * The comments a person wrote, found by their account, or by their address
 * when no other account wrote them: exported with their words, and erased
 * by anonymising them, their words and their place in the thread kept
 * (I-285, I-278). A comment with the address that another account wrote is
 * that account's, kept and counted with its reason.
 */
final class CommentsHolder implements PersonalDataHolder
{
    private const int EXPORT_PAGE = 100;

    private const int ERASE_PAGE = 200;

    public function __construct(private readonly CommentStatusRegistry $statuses) {}

    public function key(): string
    {
        return 'comments';
    }

    public function label(): string
    {
        return __('Comments');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $rows = DB::table('comments')
            ->join('contents', 'contents.id', '=', 'comments.content_id')
            ->where(fn (Builder $query) => $this->theirs($query, $subject))
            ->where('comments.status', '<>', CommentStatus::REMOVED)
            ->orderBy('comments.id')
            ->offset(($page - 1) * self::EXPORT_PAGE)
            ->limit(self::EXPORT_PAGE + 1)
            ->get([
                'comments.id', 'comments.author_name', 'comments.author_email', 'comments.author_url', 'comments.body',
                'comments.status', 'comments.created_at', 'comments.ip', 'comments.user_agent', 'contents.title',
            ]);

        // Made as it is exported, from the configured address, never stored (rule 9).
        $site = rtrim((string) config('app.url'), '/');
        $groups = [];

        foreach ($rows->take(self::EXPORT_PAGE) as $row) {
            $body = $row->body === null ? [] : json_decode((string) $row->body, true, flags: JSON_THROW_ON_ERROR);
            $values = [
                __('Name') => (string) $row->author_name,
                __('Email') => (string) $row->author_email,
                __('Website') => (string) $row->author_url,
                __('Comment') => CommentText::plain(is_array($body) ? $body : []),
                __('Status') => $this->statuses->has((string) $row->status) ? __($this->statuses->get((string) $row->status)->label) : (string) $row->status,
                __('Date') => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                __('Address') => $site.route('teme.comments.show', ['comment' => $row->id], false),
                __('IP address') => (string) $row->ip,
                __('Browser') => (string) $row->user_agent,
            ];

            $groups[] = new ExportGroup(
                __('Comment on “:title”', ['title' => (string) $row->title]),
                array_filter($values, fn (string $value): bool => $value !== ''),
            );
        }

        return new ExportPage($groups, $rows->count() <= self::EXPORT_PAGE);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        // Always the first page: an anonymised comment stops matching. Waited
        // on, never skipped: a skipped row would be left while the page said
        // done. updated_at stays: erasing isn't an edit.
        $erased = DB::select(<<<'SQL'
            UPDATE comments SET
                author_name = ?, author_email = NULL, author_url = NULL, author_user_id = NULL,
                ip = NULL, user_agent = NULL, commenter_token_hash = NULL, moderation = NULL
            WHERE id IN (
                SELECT id FROM comments
                WHERE (
                    (author_email = ? AND (author_user_id IS NULL OR author_user_id = ?::bigint))
                    OR author_user_id = ?::bigint
                ) AND status <> 'removed'
                ORDER BY id
                LIMIT ?
                FOR UPDATE
            )
            RETURNING id, content_id, status
            SQL, [__('Anonymous'), $subject->email, $subject->accountId, $subject->accountId, self::ERASE_PAGE]);

        $ids = array_map(fn (object $row): string => (string) $row->id, $erased);

        if ($ids !== []) {
            // An import run then leaves them as changed here, so WordPress's
            // copy never writes the name or the address back.
            DB::table('import_map')->where('teme_type', 'comment')->whereIn('teme_id', $ids)
                ->update(['facts' => DB::raw("facts - 'edited'")]);
        }

        foreach ($erased as $row) {
            if ($row->status === CommentStatus::APPROVED) {
                app(PublicChanges::class)->add(CommentPurges::changed((string) $row->content_id, (string) $row->id));
            }
        }

        // Counted once, on the first page: they never change, so never stop matching.
        $kept = $page === 1 ? $this->anotherAccounts($subject) : 0;

        return new ErasePage(
            count($erased),
            $kept,
            $kept === 0 ? [] : [__('Some comments with this address belong to another account, so they were kept.')],
            count($erased) < self::ERASE_PAGE,
        );
    }

    private function theirs(Builder $query, PersonalDataSubject $subject): void
    {
        $query->where(function (Builder $byAddress) use ($subject): void {
            $byAddress->where('comments.author_email', $subject->email)
                ->where(fn (Builder $unlinked) => $subject->accountId === null
                    ? $unlinked->whereNull('comments.author_user_id')
                    : $unlinked->whereNull('comments.author_user_id')->orWhere('comments.author_user_id', (int) $subject->accountId));
        });

        if ($subject->accountId !== null) {
            $query->orWhere('comments.author_user_id', (int) $subject->accountId);
        }
    }

    /**
     * Returns how many comments with the subject's address another account
     * wrote.
     */
    private function anotherAccounts(PersonalDataSubject $subject): int
    {
        return DB::table('comments')
            ->where('author_email', $subject->email)
            ->whereNotNull('author_user_id')
            ->when($subject->accountId !== null, fn (Builder $query) => $query->where('author_user_id', '<>', (int) $subject->accountId))
            ->where('status', '<>', CommentStatus::REMOVED)
            ->count();
    }
}
