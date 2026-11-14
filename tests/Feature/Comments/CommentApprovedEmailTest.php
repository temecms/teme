<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\Jobs\SendCommentApprovedEmail;
use App\Domain\Comments\Notifications\CommentApprovedNotice;
use App\Domain\Comments\Notifications\CommentWaitingNotice;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->item = cmItem($this->author, 'Harbour news');
    $this->lines = fn (array $lines): array => array_map(fn (mixed $line): string => (string) $line, $lines);
});

it('words the author\'s email as E6 does, with Comments for an author who looks after the item (E6, D11)', function (): void {
    cmSettings(['approve_returning' => false]);
    $submitted = cmSubmit($this->item, 'Lovely piece about the harbour.');
    $url = rtrim((string) config('app.url'), '/');
    $mail = cmMail($this->author, CommentApprovedNotice::class);

    expect($mail->subject)->toBe('New comment on “Harbour news”')
        ->and(($this->lines)($mail->introLines))->toBe([
            'Ada Guest commented on your post “Harbour news”.',
            '> Lovely piece about the harbour.',
        ])
        ->and($mail->actionText)->toBe('Read it on the site')
        ->and($mail->actionUrl)->toBe($url.'/_teme/comment/'.$submitted->commentId)
        ->and(($this->lines)($mail->outroLines))->toBe([
            'You can also reply to it or moderate it in [Comments]('.$url.'/admin/comments?comment='.$submitted->commentId.').',
        ])
        ->and($mail->viewData['footer'])->toBe([
            'You get this email because you wrote this post.',
            'To stop it, turn it off in [your account’s notifications]('.$url.'/admin/account/notifications).',
        ]);
});

it('emails an author whatever their role when a moderator approves, leaving Comments out for one who doesn\'t look after it (E6)', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $item = lifecycleDraft($contributor, 'Tide tables');
    listsPublish($item, $this->editor);
    $comment = cmComment($item->refresh(), 'pending');

    cmModerate($comment, 'approved', $this->editor);

    $mail = cmMail($contributor, CommentApprovedNotice::class);

    expect(($this->lines)($mail->outroLines))->toBe([])
        ->and($mail->viewData['footer'][0])->toBe('You get this email because you wrote this post.');
});

it('emails no author about their own comment, their own approval, once they turned it off, with the email off, or once the item is unpublished (D8, D9, D12)', function (string $case): void {
    match ($case) {
        'their own comment' => [cmSubmit($this->item, 'A note from the author.', ['actorId' => (string) $this->author->id])],
        'their own approval' => [cmModerate(cmComment($this->item, 'pending'), 'approved', $this->author)],
        'turned off' => [lscrPrefer($this->author, [NoticePreferences::PREFIX.CommentEmails::APPROVED => []]), cmModerate(cmComment($this->item, 'pending'), 'approved', $this->editor)],
        'the email off' => [cmSettings(['email_authors' => false]), cmModerate(cmComment($this->item, 'pending'), 'approved', $this->editor)],
        'unpublished' => [
            $waiting = cmComment($this->item, 'pending'),
            app(UnpublishContent::class)->execute(new ContentActionInput($this->item->id, (string) $this->editor->id)),
            cmModerate($waiting, 'approved', $this->editor),
        ],
    };

    Notification::assertNotSentTo($this->author, CommentApprovedNotice::class);
})->with(['their own comment', 'their own approval', 'turned off', 'the email off', 'unpublished']);

it('sends nothing when Restore puts back a comment that was shown before (D8)', function (): void {
    $comment = cmComment($this->item);
    cmModerate($comment, 'spam', $this->editor);
    cmModerate($comment, 'restore', $this->editor);

    expect($comment->refresh()->status)->toBe('approved');
    Notification::assertNotSentTo($this->author, CommentApprovedNotice::class);
});

it('emails the author about a reply written in the admin, and about the waiting comment it approves (D8)', function (): void {
    $waiting = cmComment($this->item, 'pending');
    $posted = cmReply($waiting, $this->editor, 'Thanks, the date is fixed now.');

    expect(Notification::sent($this->author, CommentApprovedNotice::class)->map(fn (CommentApprovedNotice $notice): string => $notice->commentId)->all())
        ->toEqualCanonicalizing([$waiting->id, $posted->replyId]);
});

it('reads everything again when its job runs: a comment unapproved meanwhile emails no one (D8)', function (): void {
    Queue::fake();
    $comment = cmComment($this->item, 'pending');
    cmModerate($comment, 'approved', $this->editor);
    $job = Queue::pushed(SendCommentApprovedEmail::class)->sole();

    cmModerate($comment, 'pending', $this->editor);
    cmRunJob($job);

    Notification::assertNothingSent();
});

it('sends from the queue with the approver as its actor, asks for no elevation and runs under DeniedElevation (D9, B-098)', function (): void {
    Queue::fake();
    cmModerate(cmComment($this->item, 'pending'), 'approved', $this->editor);
    $job = Queue::pushed(SendCommentApprovedEmail::class)->sole();
    $this->app->instance(Elevation::class, new DeniedElevation);

    cmRunJob($job);

    expect($job->actorId)->toBe((string) $this->editor->id);
    Notification::assertSentTo($this->author, CommentApprovedNotice::class);
});

it('carries no IP address, browser or email address in either email (E5, E6, I-270)', function (): void {
    cmSettings(['approve_returning' => false]);
    cmSubmit($this->item, 'Approved at once.');
    cmSettings(['hold_all' => true]);
    cmSubmit($this->item, 'Held for approval.', ['name' => 'Bola Ade', 'email' => 'bola@example.com']);

    foreach ([cmMail($this->author, CommentApprovedNotice::class), cmMail($this->editor, CommentWaitingNotice::class)] as $mail) {
        expect((string) $mail->render())->not->toContain('203.0.113.7')->not->toContain('Mozilla/5.0 (Test)')
            ->not->toContain('ada@example.com')->not->toContain('bola@example.com');
    }
});
