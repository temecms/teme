<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupAlert;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Backups\Operations\Input\RaiseBackupAlertInput;
use App\Domain\Backups\Operations\RaiseBackupAlert;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'UTC', 'site_title' => 'Teme Test Site'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    $this->seed(RoleSeeder::class);
    VerifyDatabase::unnamed();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admins = [User::factory()->create()->assignRole('administrator'), User::factory()->create()->assignRole('administrator')];
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->suspended = User::factory()->create(['suspended_at' => now()])->assignRole('administrator');
    $this->unverified = User::factory()->unverified()->create()->assignRole('administrator');
    $this->place = BackupRecords::destination();
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    $this->raise = fn (?string $actorId = null): ?string => app(RaiseBackupAlert::class)->execute(new RaiseBackupAlertInput($actorId));
    $this->fail = fn (): mixed => BackupRecords::run(['state' => BackupRunState::Failed, 'message' => 'No place answered, so nothing was backed up.', 'started_at' => now(), 'ended_at' => now()]);
});

afterEach(function (): void {
    OtherSession::close();
});

it('emails every administrator at once when a backup fails, and no one else', function (): void {
    ($this->fail)();

    $id = ($this->raise)();
    $alert = BackupAlert::query()->findOrFail($id);

    expect($alert->kind)->toBe(BackupAlertKind::Failing)
        ->and($alert->problems)->toBe(['The last backup failed: No place answered, so nothing was backed up.']);

    Notification::assertSentTo($this->admins, BackupAlertNotice::class, fn (BackupAlertNotice $notice): bool => $notice->kind === BackupAlertKind::Failing
        && $notice->problems === $alert->problems
        && $notice->site === 'Teme Test Site'
        && $notice->lastGood === null);
    Notification::assertNotSentTo([$this->editor, $this->suspended, $this->unverified], BackupAlertNotice::class);
});

it('decides once, under its lock, however often it’s asked', function (): void {
    ($this->fail)();

    ($this->raise)();
    ($this->raise)();
    ($this->raise)();

    expect(BackupAlert::query()->count())->toBe(1);

    Notification::assertSentTimes(BackupAlertNotice::class, 2);
});

it('emails at most once a day while it keeps failing, as a reminder', function (): void {
    ($this->fail)();
    ($this->raise)();

    $this->travel(23)->hours();
    ($this->fail)();

    expect(($this->raise)())->toBeNull();

    $this->travel(1)->hours();
    $id = ($this->raise)();

    expect(BackupAlert::query()->findOrFail($id)->kind)->toBe(BackupAlertKind::Reminder);

    Notification::assertSentTo($this->admins[0], BackupAlertNotice::class, fn (BackupAlertNotice $notice): bool => $notice->kind === BackupAlertKind::Reminder
        && $notice->since === '18 October 2026 at 10:00');
});

it('emails a second, different failure at once, on its own, and reminds of each a day after it was last told', function (): void {
    $backupFailed = 'The last backup failed: No place answered, so nothing was backed up.';
    $readBackFailed = 'The last nightly read-back failed: Place 1 holds other backups than the ones Teme made there.';
    ($this->fail)();
    ($this->raise)();

    $this->travel(1)->hours();
    BackupRecords::run(['kind' => BackupRunKind::ReadBack, 'state' => BackupRunState::Failed, 'message' => 'Place 1 holds other backups than the ones Teme made there.', 'started_at' => now(), 'ended_at' => now()]);
    $second = BackupAlert::query()->findOrFail(($this->raise)());

    expect($second->kind)->toBe(BackupAlertKind::Failing)
        ->and($second->problems)->toBe([$readBackFailed])
        ->and(($this->raise)())->toBeNull();

    // A day after the backup's failure was told, 23 hours after the read-back's.
    $this->travel(23)->hours();
    ($this->fail)();
    $first = BackupAlert::query()->findOrFail(($this->raise)());

    expect($first->kind)->toBe(BackupAlertKind::Reminder)
        ->and($first->problems)->toBe([$backupFailed])
        ->and(($this->raise)())->toBeNull();

    $this->travel(1)->hours();
    ($this->fail)();
    $then = BackupAlert::query()->findOrFail(($this->raise)());

    expect($then->kind)->toBe(BackupAlertKind::Reminder)
        ->and($then->problems)->toBe([$readBackFailed]);

    Notification::assertSentTo($this->admins[0], BackupAlertNotice::class, fn (BackupAlertNotice $notice): bool => $notice->kind === BackupAlertKind::Reminder
        && $notice->problems === [$readBackFailed]
        && $notice->since === '18 October 2026 at 11:00');
});

it('emails once when backups work again, and not while red for a reason that isn’t a failure', function (): void {
    ($this->fail)();
    ($this->raise)();
    $this->travel(1)->minutes();
    BackupRecords::run(['started_at' => now(), 'ended_at' => now()]);

    $id = ($this->raise)();

    expect(BackupAlert::query()->findOrFail($id)->kind)->toBe(BackupAlertKind::Fixed)
        ->and(BackupAlert::query()->findOrFail($id)->problems)->toBe([])
        ->and(($this->raise)())->toBeNull();

    // Failing again, then backups no longer set up: red, but nothing failed, so no "working again".
    $this->travel(1)->minutes();
    ($this->fail)();
    ($this->raise)();
    $this->place->keys()->delete();

    expect(($this->raise)())->toBeNull()
        ->and(BackupAlert::query()->orderByDesc('raised_at')->value('kind'))->toBe(BackupAlertKind::Failing);
});

it('never emails before backups are set up', function (): void {
    $this->place->keys()->delete();

    expect(($this->raise)())->toBeNull()
        ->and(BackupAlert::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('writes nothing when authorization fails', function (): void {
    ($this->fail)();

    expect(fn () => ($this->raise)((string) $this->editor->id))->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupAlert::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('writes the email’s words for a failure, a reminder and a fix', function (): void {
    $failing = (new BackupAlertNotice(BackupAlertKind::Failing, ['The last backup failed: *Place* 1 didn’t answer.'], 'Teme Test Site', '17 October 2026 at 02:14', null))->toMail($this->admins[0]);
    $reminder = (new BackupAlertNotice(BackupAlertKind::Reminder, ['The last backup failed: X.'], 'Teme Test Site', null, '18 October 2026 at 10:00'))->toMail($this->admins[0]);
    $fixed = (new BackupAlertNotice(BackupAlertKind::Fixed, [], 'Teme Test Site', '19 October 2026 at 02:20', '18 October 2026 at 10:00'))->toMail($this->admins[0]);
    $server = 'At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.';
    $everyone = 'While the problem lasts, a reminder comes once a day, and another email says when backups work again.';

    expect($failing->subject)->toBe('Backups of Teme Test Site are failing')
        ->and(array_map(strval(...), $failing->introLines))->toBe([
            'Something has gone wrong with the backups of Teme Test Site:',
            'The last backup failed: \*Place\* 1 didn’t answer.',
            'The newest good backup is from 17 October 2026 at 02:14.',
            $server,
            $everyone,
        ])
        ->and($failing->actionText)->toBe('See the backups')
        ->and($failing->actionUrl)->toBe(route('tools.backups.index'))
        ->and($reminder->subject)->toBe('Backups of Teme Test Site are still failing')
        ->and(array_map(strval(...), $reminder->introLines))->toBe([
            'The backups of Teme Test Site are still failing, as they have been since 18 October 2026 at 10:00:',
            'The last backup failed: X.',
            'There’s no good backup yet.',
            $server,
            $everyone,
        ])
        ->and($fixed->subject)->toBe('Backups of Teme Test Site are working again')
        ->and(array_map(strval(...), $fixed->introLines))->toBe([
            'The backups of Teme Test Site are working again.',
            'The newest backup is from 19 October 2026 at 02:20, and its checks passed.',
            'They had been failing since 18 October 2026 at 10:00.',
        ])
        ->and($fixed->viewData['footer'])->toBe(['Every administrator of Teme Test Site gets this email.']);
});

it('raises the alert from teme:backup:due every minute, even while another holds the lock', function (): void {
    ($this->fail)();
    OtherSession::holdBackupLock();

    $this->artisan('teme:backup:due')->doesntExpectOutputToContain('backup')->assertExitCode(0);

    Notification::assertSentTo($this->admins, BackupAlertNotice::class);
});

it('keeps only its kinds, and problems as a list, empty exactly for a fix', function (): void {
    $row = fn (string $kind, string $problems): Closure => fn () => DB::transaction(fn () => DB::table('backup_alerts')->insert([
        'id' => strtolower((string) Str::ulid()), 'kind' => $kind, 'problems' => $problems, 'raised_at' => now(),
    ]));

    expect($row('failing', '["A."]'))->not->toThrow(Throwable::class)
        ->and($row('fixed', '[]'))->not->toThrow(Throwable::class)
        ->and($row('warned', '["A."]'))->toThrow(QueryException::class)
        ->and($row('failing', '{}'))->toThrow(QueryException::class)
        ->and($row('failing', '[]'))->toThrow(QueryException::class)
        ->and($row('fixed', '["A."]'))->toThrow(QueryException::class);
});
