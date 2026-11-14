<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos'], BackupSettings::GROUP => ['hour' => '02:00']]);
    $this->now = CarbonImmutable::parse('2026-10-20 09:00', 'UTC');
    $this->travelTo($this->now);
    $this->line = fn (BackupVerdict $verdict, array $places) => app(BackupsScreen::class)->verdictLine($verdict, collect($places), $this->now);
});

it('leads with 1.12e’s first problem, then its other problems and its cautions', function (): void {
    $verdict = new BackupVerdict(['First problem.', 'Second problem.'], ['First problem.'], ['A caution.'], null);

    expect(($this->line)($verdict, [ScreenRecords::place()]))->toBe([
        'level' => 'red',
        'sentence' => 'First problem.',
        'more' => ['Second problem.', 'A caution.'],
    ]);
});

it('leads with 1.12e’s first caution when there is no problem', function (): void {
    $verdict = new BackupVerdict([], [], ['First caution.', 'Second caution.'], CarbonImmutable::parse('2026-10-20 01:20', 'UTC'));

    expect(($this->line)($verdict, [ScreenRecords::place()]))->toBe([
        'level' => 'amber',
        'sentence' => 'First caution.',
        'more' => ['Second caution.'],
    ]);
});

it('says green in its own words, from the newest good backup and where backups are kept', function (): void {
    $good = new BackupVerdict([], [], [], CarbonImmutable::parse('2026-10-20 01:14', 'UTC'));
    $folder = ScreenRecords::place();
    $offSite = ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);

    expect(($this->line)($good, [$folder, $offSite]))->toBe(['level' => 'green', 'sentence' => 'Backed up today at 02:14, on this server and off it, and checked.', 'more' => []])
        ->and(($this->line)($good, [$offSite])['sentence'])->toBe('Backed up today at 02:14, off this server, and checked.');
});

it('draws 1.12e’s verdict on the screen, word for word, as the banner says it', function (): void {
    $place = ScreenRecords::place();
    ScreenRecords::backup($place, '2026-10-20 01:05', BackupRunState::Failed, message: 'pg_dump couldn’t copy the database, so nothing was backed up. The log has its own words.');
    $verdict = app(BackupStatus::class)->verdict($this->now);

    $this->actingAs($this->admin)->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->where('verdict.level', 'red')
            ->where('verdict.sentence', $verdict->problems[0]));

    expect(app(StatusChecks::class)->forUser($this->admin)?->message)->toBe($verdict->problems[0]);
});

it('draws no verdict before setup is done, nor while a restore’s places are paused', function (): void {
    $this->actingAs($this->admin)->get('/admin/tools/backups')->assertInertia(fn (Assert $page) => $page->where('verdict', null));

    ScreenRecords::place('This server', ['paused_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->where('setup.state', 'paused')->where('verdict', null));
});

it('gives the banner and the email a way to Tools › Backups', function (): void {
    ScreenRecords::place();
    ScreenRecords::backup(ScreenRecords::place('Second', ['created_at' => now()->addSecond()]), '2026-10-20 01:05', BackupRunState::Failed, message: 'It failed.');

    $banner = app(StatusChecks::class)->forUser($this->admin);
    $mail = (new BackupAlertNotice(BackupAlertKind::Failing, ['It failed.'], 'Ledger', null, null))->toMail($this->admin);

    expect($banner?->actionLabel)->toBe('See the backups')
        ->and($banner?->actionUrl)->toBe(route('tools.backups.index'))
        ->and($mail->actionText)->toBe('See the backups')
        ->and($mail->actionUrl)->toBe(route('tools.backups.index'));
});
