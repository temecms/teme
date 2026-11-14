<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Comments\Notifications\CommentApprovedNotice;
use App\Domain\Comments\Notifications\CommentWaitingNotice;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Notices\Queries\NoticeChoices;
use App\Domain\Notices\SentAsNotice;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Users\Notifications\InvitationLink;
use App\Domain\Users\Notifications\PasswordChangedNotice;
use App\Domain\Users\Notifications\ResetPasswordLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFake;

uses(RefreshDatabase::class);

/**
 * Every email's words as they were before the seven notices moved onto the
 * layout, written out: subject, body lines before the button, the button and
 * the lines after it (Step 0, so that no word changes but the greeting, the
 * sign-off and the footer's place).
 *
 * @return array<string, array{subject: string, intro: list<string>, action: ?string, outro: list<string>}>
 */
function cnPinned(): array
{
    return [
        'waiting' => [
            'subject' => 'A comment is waiting for approval on “Harbour news”',
            'intro' => [
                0 => 'Grace \\*H\\* commented on “Harbour news”, and it’s waiting for your approval.',
                1 => '> Nice \\[post\\]',
                2 => 'Spammy',
            ],
            'action' => 'Review the comment',
            'outro' => [
                0 => '1 comment is waiting on the posts and pages you look after.',
                1 => 'You get this email because you look after comments on Garden. To stop it, turn it off in your account’s notifications.',
            ],
        ],
        'waiting-no-reason' => [
            'subject' => 'A comment is waiting for approval on “Harbour news”',
            'intro' => [
                0 => 'Grace commented on “Harbour news”, and it’s waiting for your approval.',
                1 => '> Nice',
            ],
            'action' => 'Review the comment',
            'outro' => [
                0 => '3 comments are waiting on the posts and pages you look after.',
                1 => 'You get this email because you look after comments on Garden. To stop it, turn it off in your account’s notifications.',
            ],
        ],
        'approved' => [
            'subject' => 'New comment on “Harbour news”',
            'intro' => [
                0 => 'Grace commented on your post “Harbour news”.',
                1 => '> Nice',
            ],
            'action' => 'Read it on the site',
            'outro' => [
                0 => 'You get this email because you wrote this post. To stop it, turn it off in your account’s notifications.',
            ],
        ],
        'approved-looks' => [
            'subject' => 'New comment on “Harbour news”',
            'intro' => [
                0 => 'Grace commented on your post “Harbour news”.',
                1 => '> Nice',
            ],
            'action' => 'Read it on the site',
            'outro' => [
                0 => 'You can also reply to it or moderate it in [Comments](http://localhost/admin/comments?comment=c1).',
                1 => 'You get this email because you wrote this post. To stop it, turn it off in your account’s notifications.',
            ],
        ],
        'backup-failing-0' => [
            'subject' => 'Backups of Garden are failing',
            'intro' => [
                0 => 'Something has gone wrong with the backups of Garden:',
                1 => 'Disk full',
                2 => 'The newest good backup is from 2026-10-17 02:00.',
                3 => 'At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.',
                4 => 'Every administrator of Garden gets this email. While the problem lasts, a reminder comes once a day, and another email says when backups work again.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'backup-failing-1' => [
            'subject' => 'Backups of Garden are failing',
            'intro' => [
                0 => 'Something has gone wrong with the backups of Garden:',
                1 => 'Disk full',
                2 => 'There’s no good backup yet.',
                3 => 'At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.',
                4 => 'Every administrator of Garden gets this email. While the problem lasts, a reminder comes once a day, and another email says when backups work again.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'backup-reminder-2' => [
            'subject' => 'Backups of Garden are still failing',
            'intro' => [
                0 => 'The backups of Garden are still failing, as they have been since 2026-10-18:',
                1 => 'Disk full',
                2 => 'The newest good backup is from 2026-10-17.',
                3 => 'At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.',
                4 => 'Every administrator of Garden gets this email. While the problem lasts, a reminder comes once a day, and another email says when backups work again.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'backup-reminder-3' => [
            'subject' => 'Backups of Garden are still failing',
            'intro' => [
                0 => 'The backups of Garden are still failing:',
                1 => 'Disk full',
                2 => 'There’s no good backup yet.',
                3 => 'At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.',
                4 => 'Every administrator of Garden gets this email. While the problem lasts, a reminder comes once a day, and another email says when backups work again.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'backup-fixed-4' => [
            'subject' => 'Backups of Garden are working again',
            'intro' => [
                0 => 'The backups of Garden are working again.',
                1 => 'The newest backup is from 2026-10-19, and its checks passed.',
                2 => 'They had been failing since 2026-10-18.',
                3 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'backup-fixed-5' => [
            'subject' => 'Backups of Garden are working again',
            'intro' => [
                0 => 'The backups of Garden are working again.',
                1 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the backups',
            'outro' => [
            ],
        ],
        'update-requested-up-who' => [
            'subject' => 'Teme 1.1.0 was requested for Garden',
            'intro' => [
                0 => 'Ada asked for Garden to be updated from Teme 1.0.0 to 1.1.0. The updater starts within a minute; until then it can be cancelled on Tools › Updates.',
                1 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-requested-up-nobody' => [
            'subject' => 'Teme 1.1.0 was requested for Garden',
            'intro' => [
                0 => 'Someone at the server asked for Garden to be updated from Teme 1.0.0 to 1.1.0. The updater starts within a minute; until then it can be cancelled on Tools › Updates.',
                1 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-started-up-who' => [
            'subject' => 'The update of Garden to Teme 1.1.0 has begun',
            'intro' => [
                0 => 'The updater began updating Garden from Teme 1.0.0 to 1.1.0. If no administrator asked for this, check Tools › Updates now.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-started-up-nobody' => [
            'subject' => 'The update of Garden to Teme 1.1.0 has begun',
            'intro' => [
                0 => 'The updater began updating Garden from Teme 1.0.0 to 1.1.0. If no administrator asked for this, check Tools › Updates now.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-done-up-who' => [
            'subject' => 'Garden now runs Teme 1.1.0',
            'intro' => [
                0 => 'Garden was updated from Teme 1.0.0 to 1.1.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-done-up-nobody' => [
            'subject' => 'Garden now runs Teme 1.1.0',
            'intro' => [
                0 => 'Garden was updated from Teme 1.0.0 to 1.1.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-switched-back-up-who' => [
            'subject' => 'The update of Garden to Teme 1.1.0 was switched back',
            'intro' => [
                0 => 'It failed. Every part of Garden runs Teme 1.0.0 again.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-switched-back-up-nobody' => [
            'subject' => 'The update of Garden to Teme 1.1.0 was switched back',
            'intro' => [
                0 => 'It failed. Every part of Garden runs Teme 1.0.0 again.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-failed-up-who' => [
            'subject' => 'The update of Garden to Teme 1.1.0 stopped',
            'intro' => [
                0 => 'It failed. Garden still runs Teme 1.0.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-failed-up-nobody' => [
            'subject' => 'The update of Garden to Teme 1.1.0 stopped',
            'intro' => [
                0 => 'It failed. Garden still runs Teme 1.0.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-requested-rb-who' => [
            'subject' => 'Teme 1.1.0 was requested for Garden',
            'intro' => [
                0 => 'Ada asked for Garden to be rolled back from Teme 1.0.0 to 1.1.0. The updater starts within a minute; until then it can be cancelled on Tools › Updates.',
                1 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-requested-rb-nobody' => [
            'subject' => 'Teme 1.1.0 was requested for Garden',
            'intro' => [
                0 => 'Someone at the server asked for Garden to be rolled back from Teme 1.0.0 to 1.1.0. The updater starts within a minute; until then it can be cancelled on Tools › Updates.',
                1 => 'Every administrator of Garden gets this email.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-started-rb-who' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 has begun',
            'intro' => [
                0 => 'The updater began rolling back Garden from Teme 1.0.0 to 1.1.0. If no administrator asked for this, check Tools › Updates now.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-started-rb-nobody' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 has begun',
            'intro' => [
                0 => 'The updater began rolling back Garden from Teme 1.0.0 to 1.1.0. If no administrator asked for this, check Tools › Updates now.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-done-rb-who' => [
            'subject' => 'Garden now runs Teme 1.1.0',
            'intro' => [
                0 => 'Garden was rolled back from Teme 1.0.0 to 1.1.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-done-rb-nobody' => [
            'subject' => 'Garden now runs Teme 1.1.0',
            'intro' => [
                0 => 'Garden was rolled back from Teme 1.0.0 to 1.1.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-switched-back-rb-who' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 was switched back',
            'intro' => [
                0 => 'It failed. Every part of Garden runs Teme 1.0.0 again.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-switched-back-rb-nobody' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 was switched back',
            'intro' => [
                0 => 'It failed. Every part of Garden runs Teme 1.0.0 again.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-failed-rb-who' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 stopped',
            'intro' => [
                0 => 'It failed. Garden still runs Teme 1.0.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'update-failed-rb-nobody' => [
            'subject' => 'The roll back of Garden to Teme 1.1.0 stopped',
            'intro' => [
                0 => 'It failed. Garden still runs Teme 1.0.0.',
            ],
            'action' => 'See the update',
            'outro' => [
            ],
        ],
        'invitation' => [
            'subject' => 'You have been invited to My Teme site',
            'intro' => [
                0 => 'An administrator of My Teme site has created an account for you.',
            ],
            'action' => 'Choose your password',
            'outro' => [
                0 => 'The link works once and expires in 7 days. If you were not expecting this, you can ignore it.',
            ],
        ],
        'changed' => [
            'subject' => 'Your password was changed',
            'intro' => [
                0 => 'The password for your account on My Teme site was just changed, and every other session was signed out.',
                1 => 'If this was not you, reset your password now and tell your site\'s administrator.',
            ],
            'action' => 'Reset password',
            'outro' => [
            ],
        ],
        'reset' => [
            'subject' => 'Reset your password',
            'intro' => [
                0 => 'You are receiving this email because we received a password reset request for your account.',
            ],
            'action' => 'Reset Password',
            'outro' => [
                0 => 'This password reset link will expire in 60 minutes.',
                1 => 'If you did not request a password reset, no further action is required.',
            ],
        ],
    ];
}

/**
 * The seven notices in every case the plan lists.
 *
 * @return array<string, Notification>
 */
function cnCases(): array
{
    $cases = [];
    $cases['waiting'] = new CommentWaitingNotice('c1', 'Grace *H*', 'Nice [post]', 'Spammy', 'Harbour news', 1, 'Garden');
    $cases['waiting-no-reason'] = new CommentWaitingNotice('c1', 'Grace', 'Nice', null, 'Harbour news', 3, 'Garden');
    $cases['approved'] = new CommentApprovedNotice('c1', 'Grace', 'Nice', 'Harbour news', 'post', false);
    $cases['approved-looks'] = new CommentApprovedNotice('c1', 'Grace', 'Nice', 'Harbour news', 'post', true);

    foreach ([[BackupAlertKind::Failing, ['Disk full'], '2026-10-17 02:00', null], [BackupAlertKind::Failing, ['Disk full'], null, null], [BackupAlertKind::Reminder, ['Disk full'], '2026-10-17', '2026-10-18'], [BackupAlertKind::Reminder, ['Disk full'], null, null], [BackupAlertKind::Fixed, [], '2026-10-19', '2026-10-18'], [BackupAlertKind::Fixed, [], null, null]] as $i => [$kind, $problems, $lastGood, $since]) {
        $cases["backup-{$kind->value}-{$i}"] = new BackupAlertNotice($kind, $problems, 'Garden', $lastGood, $since);
    }

    foreach ([false, true] as $rollback) {
        foreach (['requested', 'started', 'done', 'switched-back', 'failed'] as $stage) {
            foreach (['Ada', null] as $who) {
                $cases["update-{$stage}-".($rollback ? 'rb' : 'up').'-'.($who !== null ? 'who' : 'nobody')] = new TemeUpdateNotice($stage, '1.0.0', '1.1.0', $who, 'It failed.', 'Garden', $rollback);
            }
        }
    }

    $cases['invitation'] = new InvitationLink('tok', 1);
    $cases['changed'] = new PasswordChangedNotice;
    $cases['reset'] = new ResetPasswordLink('tok', 1);

    return $cases;
}

/**
 * @return array{subject: string, greeting: string, intro: list<string>, action: ?string, outro: list<string>, footer: list<string>}
 */
function cnWords(Notification $notice, User $to): array
{
    $mail = $notice->toMail($to);

    // The account emails read the site's own title; the others were given 'Garden'.
    $plain = fn (array $lines): array => array_map(fn (mixed $line): string => str_replace('My Teme site', 'Garden', (string) $line), $lines);

    return [
        'subject' => str_replace('My Teme site', 'Garden', (string) $mail->subject),
        'greeting' => (string) $mail->greeting,
        'intro' => $plain($mail->introLines),
        'action' => $mail->actionText,
        'outro' => $plain($mail->outroLines),
        'footer' => $plain($mail->viewData['footer'] ?? []),
    ];
}

/**
 * An email as a reader meets it, line by line in order: subject, greeting,
 * body lines before the button, the button, body lines after it, footer.
 *
 * @param  array{subject: string, greeting: string, intro: list<string>, action: ?string, outro: list<string>, footer: list<string>}  $words
 *
 * @return list<string>
 */
function cnSequence(array $words): array
{
    return [
        'subject: '.$words['subject'],
        'greeting: '.$words['greeting'],
        ...array_map(fn (string $line): string => 'body: '.$line, $words['intro']),
        ...($words['action'] === null ? [] : ['button: '.$words['action']]),
        ...array_map(fn (string $line): string => 'body: '.$line, $words['outro']),
        ...array_map(fn (string $line): string => 'footer: '.$line, $words['footer']),
    ];
}

/**
 * What today's pinned email becomes with exactly the plan's moves and the
 * layout's greeting: the comment emails' last line split into a reason and
 * the way to turn it off, in the footer; the backup alert's and the requested
 * update email's "Every administrator" sentence moved to the footer, the
 * backup alert's second sentence staying in the body where it was.
 *
 * @param  array{subject: string, intro: list<string>, action: ?string, outro: list<string>}  $pinned
 *
 * @return array{subject: string, greeting: string, intro: list<string>, action: ?string, outro: list<string>, footer: list<string>}
 */
function cnAfterMove(array $pinned): array
{
    $stop = 'To stop it, turn it off in [your account’s notifications](http://localhost/admin/account/notifications).';
    $every = 'Every administrator of Garden gets this email.';
    $footer = [];
    $move = function (array $lines) use (&$footer, $stop, $every): array {
        $kept = [];

        foreach ($lines as $line) {
            if (str_ends_with($line, ' To stop it, turn it off in your account’s notifications.')) {
                $footer[] = substr($line, 0, -strlen(' To stop it, turn it off in your account’s notifications.'));
                $footer[] = $stop;
            } elseif ($line === $every) {
                $footer[] = $every;
            } elseif (str_starts_with($line, $every.' While the problem lasts')) {
                $footer[] = $every;
                $kept[] = substr($line, strlen($every) + 1);
            } else {
                $kept[] = $line;
            }
        }

        return $kept;
    };

    $intro = $move($pinned['intro']);
    $outro = $move($pinned['outro']);

    return ['subject' => $pinned['subject'], 'greeting' => 'Hi Ada,', 'intro' => $intro, 'action' => $pinned['action'], 'outro' => $outro, 'footer' => $footer];
}

it('says every word of every email as before, in the same order, but for the greeting and the footer’s place', function (string $case): void {
    config(['app.url' => 'http://localhost']);
    $to = User::factory()->create(['name' => 'Ada']);
    // The account emails' words name the site's own title, 'My Teme site' here.
    $pinned = json_decode(str_replace('My Teme site', 'Garden', (string) json_encode(cnPinned()[$case])), true);

    expect(cnSequence(cnWords(cnCases()[$case], $to)))->toBe(cnSequence(cnAfterMove($pinned)));
})->with(array_keys(cnPinned()));

it('puts the reason in the footer: the requested update email only, never the other four', function (): void {
    config(['app.url' => 'http://localhost']);
    $to = User::factory()->create(['name' => 'Ada']);

    foreach (['requested', 'started', 'done', 'switched-back', 'failed'] as $stage) {
        $footer = cnWords(cnCases()["update-{$stage}-up-who"], $to)['footer'];

        expect($footer)->toBe($stage === 'requested' ? ['Every administrator of Garden gets this email.'] : []);
    }

    expect(cnWords(cnCases()['waiting'], $to)['footer'])->toBe([
        'You get this email because you look after comments on Garden.',
        'To stop it, turn it off in [your account’s notifications](http://localhost/admin/account/notifications).',
    ])
        ->and(cnWords(cnCases()['approved'], $to)['footer'][0])->toBe('You get this email because you wrote this post.')
        ->and(cnWords(cnCases()['backup-failing-0'], $to)['footer'])->toBe(['Every administrator of Garden gets this email.'])
        ->and(cnWords(cnCases()['invitation'], $to)['footer'])->toBe([]);
});

it('has each of the seven use SentAsNotice, answer its kind and keep no via of its own', function (string $class, string $kind): void {
    $notice = match ($class) {
        CommentWaitingNotice::class => cnCases()['waiting'],
        CommentApprovedNotice::class => cnCases()['approved'],
        BackupAlertNotice::class => cnCases()['backup-failing-0'],
        TemeUpdateNotice::class => cnCases()['update-done-up-who'],
        InvitationLink::class => cnCases()['invitation'],
        PasswordChangedNotice::class => cnCases()['changed'],
        ResetPasswordLink::class => cnCases()['reset'],
    };

    expect(class_uses($class))->toContain(SentAsNotice::class)
        ->and($notice->noticeKind())->toBe($kind)
        ->and((new ReflectionMethod($class, 'via'))->getFileName())->toEndWith('SentAsNotice.php');
})->with([
    [CommentWaitingNotice::class, 'comments.waiting'],
    [CommentApprovedNotice::class, 'comments.approved'],
    [BackupAlertNotice::class, 'backups.alert'],
    [TemeUpdateNotice::class, 'updates.update'],
    [InvitationLink::class, 'users.invitation'],
    [PasswordChangedNotice::class, 'users.password_changed'],
    [ResetPasswordLink::class, 'users.password_reset'],
]);

it('sends the comment-waiting email to nobody who chose none', function (): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');
    $other = User::factory()->create()->assignRole('editor');
    $author = User::factory()->create()->assignRole('author');
    $item = cmItem($author);
    lscrPrefer($editor, [NoticePreferences::PREFIX.'comments.waiting' => []]);
    NotificationFake::fake();

    cmSubmit($item, 'Is the ferry running?');

    NotificationFake::assertNotSentTo($editor, CommentWaitingNotice::class);
    NotificationFake::assertSentTo($other, CommentWaitingNotice::class);
});

it('sends every backup alert and update email to an administrator whatever they stored', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $choices = app(NoticeChoices::class);

    expect($choices->channelsFor($admin, 'backups.alert'))->toBe(['mail'])
        ->and($choices->channelsFor($admin, 'updates.update'))->toBe(['mail']);
});

it('still reaches an account not yet verified with the invitation and the reset, and its owner with the password notice', function (): void {
    $unverified = User::factory()->unverified()->create();

    expect((new InvitationLink('tok', 1))->via($unverified))->toBe(['mail'])
        ->and((new ResetPasswordLink('tok', 1))->via($unverified))->toBe(['mail'])
        ->and((new PasswordChangedNotice)->via($unverified))->toBe(['mail']);
});

it('renders each notice with no elevation, as the worker would', function (string $case): void {
    app()->instance(Elevation::class, new DeniedElevation);
    config(['app.url' => 'http://localhost']);
    $to = User::factory()->create(['name' => 'Ada']);

    expect((string) cnCases()[$case]->toMail($to)->render())->toContain('Hi Ada,');
})->with(['waiting', 'approved', 'backup-failing-0', 'update-done-up-who', 'invitation', 'changed', 'reset']);
