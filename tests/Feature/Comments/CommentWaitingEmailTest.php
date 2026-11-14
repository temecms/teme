<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\Jobs\SendCommentWaitingEmails;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Notifications\CommentWaitingNotice;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->item = cmItem($this->author, 'Harbour news');
    $this->lines = fn (array $lines): array => array_map(fn (mixed $line): string => (string) $line, $lines);
});

it('words the moderators\' email as E5 does, its link absolute and made as it is sent (E5, D11)', function (): void {
    cmComment($this->item, 'pending');
    $submitted = cmSubmit($this->item, "Is the ferry running?\n\nIt wasn’t on Monday.");
    $url = rtrim((string) config('app.url'), '/');
    $site = app(SettingsReader::class)->string('general', 'site_title');
    $mail = cmMail($this->editor, CommentWaitingNotice::class);

    expect($mail->subject)->toBe('A comment is waiting for approval on “Harbour news”')
        ->and(($this->lines)($mail->introLines))->toBe([
            'Ada Guest commented on “Harbour news”, and it’s waiting for your approval.',
            "> Is the ferry running?\n>\n> It wasn’t on Monday.",
            'Held: a first comment, or the first from this browser.',
        ])
        ->and($mail->actionText)->toBe('Review the comment')
        ->and($mail->actionUrl)->toBe($url.'/admin/comments?comment='.$submitted->commentId)
        ->and(($this->lines)($mail->outroLines))->toBe([
            '2 comments are waiting on the posts and pages you look after.',
        ])
        ->and($mail->viewData['footer'])->toBe([
            "You get this email because you look after comments on {$site}.",
            'To stop it, turn it off in [your account’s notifications]('.$url.'/admin/account/notifications).',
        ]);
});

it('counts for each person only what waits on the items they look after, "1 comment is" for one (E5)', function (): void {
    cmComment(cmItem($this->editor, 'Market day'), 'pending');
    cmSubmit($this->item);

    expect(($this->lines)(cmMail($this->author, CommentWaitingNotice::class)->outroLines)[0])->toBe('1 comment is waiting on the posts and pages you look after.')
        ->and(($this->lines)(cmMail($this->editor, CommentWaitingNotice::class)->outroLines)[0])->toBe('2 comments are waiting on the posts and pages you look after.');
});

it('puts a comment\'s Markdown in the email as words: [Approve](https://evil.example) is no link (D10, the design pass\'s Delicate)', function (): void {
    cmSubmit($this->item, '[Approve](https://evil.example)');
    $page = dtDom((string) cmMail($this->editor, CommentWaitingNotice::class)->render());

    expect($page->query('//a[normalize-space(.)="Approve"]')->length)->toBe(0)
        ->and($page->query('//a[contains(@href, "evil.example")]')->length)->toBe(0)
        ->and($page->query('//blockquote[contains(normalize-space(.), "[Approve](https://evil.example)")]')->length)->toBe(1);
});

it('lets nothing in a comment become formatting in the email (D10)', function (string $text, string $element, string $shown): void {
    cmSubmit($this->item, $text);
    $page = dtDom((string) cmMail($this->editor, CommentWaitingNotice::class)->render());

    expect($page->query("//blockquote//{$element}")->length)->toBe(0)
        ->and($page->query('//blockquote[contains(normalize-space(.), "'.$shown.'")]')->length)->toBe(1);
})->with([
    'emphasis' => ['**Free** _money_', 'strong', '**Free** _money_'],
    'a heading' => ['# Act now', 'h1', '# Act now'],
    'a list' => ["1. Open the link\n2. Pay", 'ol', '1. Open the link'],
    'an image' => ['![x](https://evil.example/x.png)', 'img', '![x](https://evil.example/x.png)'],
    'code' => ['`rm -rf`', 'code', '`rm -rf`'],
    'HTML' => ['<b>bold</b> & <i>more</i>', 'b', '<b>bold</b> & <i>more</i>'],
]);

it('escapes the commenter\'s name and the item\'s title too, in every line, and leaves the subject as typed (D10)', function (): void {
    $item = cmItem($this->author, '**Big** news');
    cmSubmit($item, 'Congratulations on the news.', ['name' => '[Approve](https://evil.example)', 'email' => 'mallory@example.com']);
    $mail = cmMail($this->editor, CommentWaitingNotice::class);
    $page = dtDom((string) $mail->render());

    expect($mail->subject)->toBe('A comment is waiting for approval on “**Big** news”')
        ->and($page->query('//a[normalize-space(.)="Approve"]')->length)->toBe(0)
        ->and($page->query('//strong[normalize-space(.)="Big"]')->length)->toBe(0)
        ->and($page->query('//p[contains(normalize-space(.), "[Approve](https://evil.example) commented on “**Big** news”")]')->length)->toBe(1);
});

it('emails everyone who looks after the item when a comment waits, and no one else (E5, I-483, D7)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $otherAuthor = User::factory()->create()->assignRole('author');
    $contributor = User::factory()->create()->assignRole('contributor');
    $suspended = User::factory()->suspended()->create()->assignRole('editor');
    $unverified = User::factory()->unverified()->create()->assignRole('editor');

    cmSubmit($this->item);

    foreach ([$this->editor, $admin, $this->author] as $person) {
        Notification::assertSentToTimes($person, CommentWaitingNotice::class, 1);
    }

    foreach ([$otherAuthor, $contributor, $suspended, $unverified] as $person) {
        Notification::assertNotSentTo($person, CommentWaitingNotice::class);
    }
});

it('leaves out the person whose action queued it and whoever turned the email off (D9, D12)', function (): void {
    Queue::fake();
    $submitted = cmSubmit($this->item);
    $admin = User::factory()->create()->assignRole('administrator');
    lscrPrefer($this->author, [NoticePreferences::PREFIX.CommentEmails::WAITING => []]);

    cmRunJob(new SendCommentWaitingEmails($submitted->commentId, (string) $this->editor->id));

    Notification::assertNotSentTo($this->editor, CommentWaitingNotice::class);
    Notification::assertNotSentTo($this->author, CommentWaitingNotice::class);
    Notification::assertSentTo($admin, CommentWaitingNotice::class);
});

it('sends nothing for a comment that doesn\'t arrive waiting, or while the email is off (E5, D16)', function (string $case): void {
    match ($case) {
        'spam' => [cmSettings(['block_list' => 'casino']), cmSubmit($this->item, 'Cheap casino chips.')],
        'approved at once' => [cmSettings(['approve_returning' => false]), cmSubmit($this->item)],
        'unapproved by a moderator' => [cmModerate(cmComment($this->item), 'pending', $this->editor)],
        'the email off' => [cmSettings(['email_moderators' => false]), cmSubmit($this->item)],
    };

    Notification::assertNotSentTo([$this->editor, $this->author], CommentWaitingNotice::class);
})->with(['spam', 'approved at once', 'unapproved by a moderator', 'the email off']);

it('sends nothing once comments or the email are switched off before its job runs (D7)', function (array $values, string $group): void {
    Queue::fake();
    $submitted = cmSubmit($this->item);
    cmSettings($values, $group);

    cmRunJob(new SendCommentWaitingEmails($submitted->commentId, null));

    Notification::assertNothingSent();
})->with([
    'comments off' => [['comments' => 'off'], 'modules'],
    'the email off' => [['email_moderators' => false], 'discussion'],
]);

it('reads everything again when its job runs: a comment approved meanwhile, or a moderator who lost the role, gets nothing (D7)', function (): void {
    Queue::fake();
    $first = cmSubmit($this->item, 'First, approved before the job runs.');
    $second = cmSubmit($this->item, 'Second, still waiting.', ['name' => 'Bola Ade', 'email' => 'bola@example.com']);
    $jobs = Queue::pushed(SendCommentWaitingEmails::class)->values();

    cmModerate(Comment::query()->findOrFail($first->commentId), 'approved', $this->author);
    $this->editor->syncRoles(['author']);

    foreach ($jobs as $job) {
        cmRunJob($job);
    }

    Notification::assertNotSentTo($this->editor, CommentWaitingNotice::class);
    Notification::assertSentToTimes($this->author, CommentWaitingNotice::class, 1);

    expect(Notification::sent($this->author, CommentWaitingNotice::class)->sole()->commentId)->toBe($second->commentId);
});

it('sends from the queue as no one in particular: its job carries its actor, asks for no elevation and runs under DeniedElevation (D9, B-098)', function (): void {
    Queue::fake();
    cmSubmit($this->item);
    $job = Queue::pushed(SendCommentWaitingEmails::class)->sole();
    $this->app->instance(Elevation::class, new DeniedElevation);

    cmRunJob($job);

    expect($job->actorId)->toBeNull();
    Notification::assertSentTo([$this->editor, $this->author], CommentWaitingNotice::class);
});
