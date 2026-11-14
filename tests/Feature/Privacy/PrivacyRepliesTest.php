<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Notifications\PersonalDataErased;
use App\Domain\Privacy\Notifications\PersonalDataExported;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    Notification::fake();
    cmSettings(['site_title' => 'Plant *Notes*'], 'general');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->offered = [new RecordingHolder('acme.first', 'First', [new ExportPage([new ExportGroup('One', ['Name' => 'Ada'])], true)])];
    app(HookDispatcher::class)->subscriber('acme/test', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => $this->offered);

    $this->run = function (string $kind, bool $reply = true): string {
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput($kind, 'ada@example.com', 'Asked by email.', $reply));

        for ($steps = 0; $steps < 30; $steps++) {
            $step = $kind === 'export'
                ? app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($id))
                : app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id));

            if ($step->done) {
                break;
            }
        }

        return $id;
    };
    $this->mail = function (string $notice): MailMessage {
        $mail = null;

        Notification::assertSentOnDemand($notice, function (object $sent, array $channels, object $notifiable) use (&$mail): bool {
            $mail = $sent->toMail($notifiable);

            return true;
        });

        return $mail;
    };
    $this->sentTo = function (string $notice): array {
        $addresses = [];

        Notification::assertSentOnDemand($notice, function (object $sent, array $channels, object $notifiable) use (&$addresses): bool {
            $addresses[] = $notifiable->routes['mail'] ?? null;

            return true;
        });

        return $addresses;
    };
});

it('emails the export to the address after the last step, with its words and the file attached as it is when sent', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $id = ($this->run)('export');

    expect(($this->sentTo)(PersonalDataExported::class))->toBe(['ada@example.com']);

    Storage::disk('local')->put("privacy/{$id}.json", '{"read": "when sent"}');
    $mail = ($this->mail)(PersonalDataExported::class);

    expect($mail->subject)->toBe('Your personal data from Plant *Notes*')
        ->and($mail->introLines)->toBe([
            'You asked for a copy of the personal data this site holds about you. It is attached, as a JSON file.',
            "Some of it may also be in the site's backups until they are replaced.",
        ])
        ->and($mail->viewData['footer'])->toBe(['You get this email because you asked this site for your personal data.'])
        ->and($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toBe('personal-data.json')
        ->and($mail->rawAttachments[0]['options']['mime'])->toBe('application/json')
        ->and($mail->rawAttachments[0]['data'])->toBe('{"read": "when sent"}');
});

it('says the file is gone, not that it is attached, when it was deleted before the email was sent', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $id = ($this->run)('export');

    Storage::disk('local')->delete("privacy/{$id}.json");
    $mail = ($this->mail)(PersonalDataExported::class);

    expect($mail->introLines)->toBe([
        'You asked for a copy of the personal data this site holds about you.',
        "The file was no longer on the site when this email was sent. The site's administrators can make it again.",
        "Some of it may also be in the site's backups until they are replaced.",
    ])
        ->and($mail->viewData['footer'])->toBe(['You get this email because you asked this site for your personal data.'])
        ->and($mail->rawAttachments)->toBe([]);
});

it('emails nothing on a rollback, nor when the request said not to reply', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    cacheRolledBack(fn () => ($this->run)('export'));
    ($this->run)('export', false);

    Notification::assertNothingSent();
});

it('sends no file over 20 MB, and still ends the request done', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $this->offered = [new RecordingHolder('acme.big', 'Big', [new ExportPage([new ExportGroup('Big', ['Text' => str_repeat('a', 21 * 1024 * 1024)])], true)])];

    $id = ($this->run)('export');

    expect(DB::table('privacy_requests')->where('id', $id)->value('status'))->toBe('done');
    Notification::assertNothingSent();
});

it('emails the erasure with core’s reasons each once, a plugin’s as one line of its own, a missing plugin by its label made plain, and the backups line', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);
    $this->offered = [
        new RecordingHolder('acme.keeper', 'Keeper', [], [new ErasePage(0, 1, ['*Kept* by [the plugin](https://example.com).'], true)]),
        new RecordingHolder('acme.gone', 'Gone *Plugin*'),
    ];
    $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', 'ada@example.com', 'Asked by email.'));
    $this->offered = [$this->offered[0]];

    while (! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id))->done);

    expect(($this->sentTo)(PersonalDataErased::class))->toBe(['ada@example.com']);

    $mail = ($this->mail)(PersonalDataErased::class);

    expect($mail->subject)->toBe('Your personal data on Plant *Notes* was erased')
        ->and($mail->introLines[0])->toBe('You asked for your personal data on this site to be erased, and it has been.')
        ->and($mail->introLines[1])->toBe('Some was kept:')
        ->and($mail->introLines)->toContain('The account is kept. To remove it, delete it under Users.')
        ->and($mail->introLines)->toContain('Some data held by an installed plugin was kept. The site\'s administrators can tell you why.')
        ->and($mail->introLines)->toContain('A plugin that held data when the request started was no longer installed: Gone \\*Plugin\\*.')
        ->and($mail->introLines)->not->toContain('A plugin that held data when the request started was no longer installed.')
        ->and(implode("\n", $mail->introLines))->not->toContain('*Kept*')
        ->and(array_slice($mail->introLines, -1))->toBe(["The site's backups may still hold this data until they are replaced."])
        ->and($mail->viewData['footer'])->toBe(['You get this email because you asked this site to erase your personal data.'])
        ->and(array_count_values($mail->introLines))->each->toBe(1);
});

it('writes core’s reasons with no Markdown character in them', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com'])->assignRole('editor');
    $item = cmItem($ada);
    cmComment($item);
    cmComment($item, attributes: ['author_user_id' => User::factory()->create()->id]);
    cmSettings(['admin_email' => 'ada@example.com'], 'general');
    cmSettings(['hold_list' => 'ada@example.com']);
    DB::table('security_events')->insert(['type' => 'sign_in.failed', 'subject' => 'user:'.$ada->id, 'user_id' => $ada->id, 'ip' => '', 'hour' => now(), 'counted' => true, 'count' => 1, 'context' => '{}', 'first_at' => now(), 'last_at' => now()]);
    DB::table('inbox_items')->insert(['id' => cmId(), 'user_id' => $ada->id, 'kind' => 'export.done', 'owner' => 'core', 'body' => '{"title": "Done", "text": null, "link": null}', 'created_at' => now()]);
    actvRow($ada);
    DB::table('settings_changes')->insert(['id' => cmId(), 'group_key' => 'general', 'changed_keys' => '["admin_email"]', 'before' => '{"admin_email": "ada@example.com"}', 'after' => '{"admin_email": "x@example.com"}', 'origin' => 'human', 'created_at' => now()]);
    $this->offered = [];

    $id = ($this->run)('erase');
    $reasons = array_merge(...array_column(DB::table('privacy_requests')->where('id', $id)->get()->map(fn (object $row): array => json_decode((string) $row->results, true))->first(), 'reasons'));

    expect($reasons)->toHaveCount(10);

    foreach ($reasons as $reason) {
        expect($reason)->not->toMatch('/[\\\\`*_\[\]!|~#]/');
    }
});

it('renders each reply with nothing but what it carries, under denied elevation', function (): void {
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), []));
    $export = ($this->run)('export');
    app()->instance(Elevation::class, new DeniedElevation);
    $notifiable = (new AnonymousNotifiable)->route('mail', 'ada@example.com');

    expect((new PersonalDataExported("privacy/{$export}.json"))->toMail($notifiable))->toBeInstanceOf(MailMessage::class)
        ->and((new PersonalDataErased(['Kept.']))->toMail($notifiable)->introLines)->toContain('Kept.');
});

/**
 * A reply's words in the order a reader meets them: subject, greeting, body,
 * button, then the footer, wherever it is kept.
 *
 * @return list<string>
 */
function prReplyWords(MailMessage $mail): array
{
    return array_values(array_filter([
        $mail->subject,
        $mail->greeting,
        ...$mail->introLines,
        $mail->actionText,
        ...$mail->outroLines,
        ...(array) ($mail->viewData['footer'] ?? []),
    ], fn (mixed $part): bool => $part !== null));
}

it('keeps each reply’s words in the same order as they were before the replies were notices', function (): void {
    $notifiable = (new AnonymousNotifiable)->route('mail', 'ada@example.com');
    Storage::disk('local')->put('privacy/pin.json', '{}');

    expect(prReplyWords((new PersonalDataExported('privacy/pin.json'))->toMail($notifiable)))->toBe([
        'Your personal data from Plant *Notes*',
        'You asked for a copy of the personal data this site holds about you. It is attached, as a JSON file.',
        "Some of it may also be in the site's backups until they are replaced.",
        'You get this email because you asked this site for your personal data.',
    ])
        ->and(prReplyWords((new PersonalDataExported('privacy/none.json'))->toMail($notifiable)))->toBe([
            'Your personal data from Plant *Notes*',
            'You asked for a copy of the personal data this site holds about you.',
            "The file was no longer on the site when this email was sent. The site's administrators can make it again.",
            "Some of it may also be in the site's backups until they are replaced.",
            'You get this email because you asked this site for your personal data.',
        ])
        ->and(prReplyWords((new PersonalDataErased([]))->toMail($notifiable)))->toBe([
            'Your personal data on Plant *Notes* was erased',
            'You asked for your personal data on this site to be erased, and it has been.',
            "The site's backups may still hold this data until they are replaced.",
            'You get this email because you asked this site to erase your personal data.',
        ])
        ->and(prReplyWords((new PersonalDataErased(['Kept one.', 'Kept two.']))->toMail($notifiable)))->toBe([
            'Your personal data on Plant *Notes* was erased',
            'You asked for your personal data on this site to be erased, and it has been.',
            'Some was kept:',
            'Kept one.',
            'Kept two.',
            "The site's backups may still hold this data until they are replaced.",
            'You get this email because you asked this site to erase your personal data.',
        ]);
});
