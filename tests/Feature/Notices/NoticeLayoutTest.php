<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Comments\Notifications\CommentWaitingNotice;
use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

uses(RefreshDatabase::class);

/**
 * @return array{html: string, text: string}
 */
function nlRender(MailMessage $mail): array
{
    return [
        'html' => (string) $mail->render(),
        'text' => (string) app(Markdown::class)->renderText($mail->markdown, $mail->data()),
    ];
}

function nlMail(object $to, string $kind = 'comments.waiting', bool $action = true, ?string $reason = null): MailMessage
{
    $mail = (new MailMessage)->subject('Subject')->line('A line of the body.');

    if ($action) {
        $mail->action('Open it', 'https://garden.example/admin/comments');
    }

    return NoticeLayout::apply($mail, $kind, $to, $reason);
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'https://garden.example', 'app.name' => 'Laravel']);
    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace']);
});

it('greets by name as a paragraph, signs with the site’s title and carries no Laravel words', function (): void {
    $out = nlRender(nlMail($this->ada));

    expect($out['html'])->toContain('Hi Ada Lovelace,')
        ->and($out['html'])->not->toMatch('/<h1[^>]*>\s*Hi Ada/')
        ->and($out['html'])->toContain('The Garden City Ledger')
        ->and($out['html'])->toContain('https://garden.example');

    foreach ($out as $body) {
        foreach (['Hello!', 'Whoops!', 'Regards,', 'Laravel', 'All rights reserved'] as $gone) {
            expect($body)->not->toContain($gone);
        }
    }

    expect($out['text'])->toContain('Hi Ada Lovelace,')
        ->and(trim(strstr($out['text'], 'Open it') ?: ''))->toContain('The Garden City Ledger');
});

it('shows a name as the characters it is, never as emphasis or a link', function (): void {
    $this->ada->update(['name' => '*Ada* [x](https://evil.example)']);

    $html = nlRender(nlMail($this->ada->refresh()))['html'];

    expect($html)->toContain('*Ada* [x](https://evil.example)')
        ->and($html)->not->toContain('<em>Ada')
        ->and($html)->not->toContain('href="https://evil.example"');
});

it('gives a listed kind a reason and the way to turn it off, a required kind its reason only', function (): void {
    $listed = nlRender(nlMail($this->ada, 'comments.waiting', reason: 'You get this email because you look after comments on :site.'))['html'];

    expect($listed)->toContain('You get this email because you look after comments on The Garden City Ledger.')
        ->and($listed)->toContain('To stop it, turn it off in')
        ->and($listed)->toContain('href="https://garden.example/admin/account/notifications"');

    $required = nlRender(nlMail($this->ada, 'backups.alert', reason: 'Every administrator of :site gets this email.'))['html'];

    expect($required)->toContain('Every administrator of The Garden City Ledger gets this email.')
        ->and($required)->not->toContain('To stop it');
});

it('has no footer for a required kind with no reason', function (): void {
    $out = nlRender(nlMail($this->ada, 'users.invitation', action: false));

    expect($out['html'])->not->toContain('class="subcopy"');
});

it('draws a footer under an email with no button', function (): void {
    $html = nlRender(nlMail($this->ada, 'comments.waiting', action: false, reason: 'Why you got this.'))['html'];

    expect($html)->toContain('Why you got this.')
        ->and($html)->not->toContain('trouble clicking');
});

it('greets nobody by name when it goes to an address', function (): void {
    $out = nlRender(nlMail(new AnonymousNotifiable, 'users.password_reset'));

    expect($out['html'])->not->toContain('Hi ')
        ->and($out['html'])->toContain('The Garden City Ledger');
});

it('dresses an email no kind sends in the same header, sign-off and footer', function (): void {
    VerifyEmail::createUrlUsing(fn (): string => 'https://garden.example/verify/1');
    $mail = (new VerifyEmail)->toMail($this->ada);
    $out = nlRender($mail);

    expect($out['html'])->toContain('The Garden City Ledger')
        ->and($out['html'])->toContain('https://garden.example')
        ->and($out['html'])->not->toContain('Laravel')
        ->and($out['html'])->not->toContain('All rights reserved');

    VerifyEmail::createUrlUsing(null);
});

it('turns a link and a heading made by someone else into plain words', function (): void {
    expect(MailText::inline("[click](https://evil.example)\n# Big"))->toBe('\[click\](https://evil.example) # Big')
        ->and(MailText::inline('# Heading'))->toStartWith('\#');
});

it('writes the real comment-waiting and backup-alert emails to the folder TEME_NOTICE_SAMPLES names, and only then', function (): void {
    $folder = getenv('TEME_NOTICE_SAMPLES');

    if ($folder === false || $folder === '') {
        $this->markTestSkipped('TEME_NOTICE_SAMPLES names no folder.');
    }

    @mkdir($folder, 0777, true);
    $editor = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $admin = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('administrator');

    $samples = [
        'comment-waiting' => (new CommentWaitingNotice('01JCOMMENT', 'Charles Babbage', "Is the ferry running?\n\nIt wasn’t on Monday.", 'Held: a first comment, or the first from this browser.', 'Harbour news', 2, 'The Garden City Ledger'))->toMail($editor),
        'backup-alert' => (new BackupAlertNotice(BackupAlertKind::Failing, ['The last backup failed: the place didn’t answer.'], 'The Garden City Ledger', '17 October 2026 at 02:14', null))->toMail($admin),
    ];

    foreach ($samples as $name => $mail) {
        $rendered = nlRender($mail);
        file_put_contents("{$folder}/{$name}.html", $rendered['html']);
        file_put_contents("{$folder}/{$name}.txt", $rendered['text']);

        expect($rendered['html'])->toContain('Hi '.($name === 'comment-waiting' ? 'Ada Lovelace' : 'Grace Hopper').',');
    }
});

it('shows the greeting and the sign-off plainly in the plain-text part, with no Markdown backslashes', function (): void {
    cmSettings(['site_title' => 'Garden_City *Ledger*'], 'general');
    $this->ada->update(['name' => 'Ada_L']);

    $out = nlRender(nlMail($this->ada->refresh()));

    expect($out['text'])->toContain('Hi Ada_L,')
        ->and($out['text'])->not->toContain('Ada\_L')
        ->and($out['text'])->toContain('Garden_City *Ledger*')
        ->and($out['text'])->not->toContain('Garden\_City')
        ->and($out['html'])->toContain('Hi Ada_L,')
        ->and($out['html'])->toContain('Garden_City');
});
