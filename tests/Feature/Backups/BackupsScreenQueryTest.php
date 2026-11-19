<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Queries\BackupsScreen;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos'], BackupSettings::GROUP => ['hour' => '02:00']]);
    $this->now = CarbonImmutable::parse('2026-10-20 09:00', 'UTC');
    $this->travelTo($this->now);
    $this->data = fn (?array $challenge = null): array => app(BackupsScreen::class)->data($this->now, $challenge);
});

it('lists the places in use, oldest first, by kind and address, and never a credential', function (): void {
    ScreenRecords::place('This server', ['location' => ['path' => '/srv/teme-backups']]);
    ScreenRecords::place('Bucket', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);
    ScreenRecords::place('Box', ['kind' => BackupDestinationKind::Sftp, 'location' => ['host' => 'backups.example.com', 'port' => 2222, 'user' => 'teme', 'path' => '/srv/teme', 'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTheScreenOnly', 'fingerprint' => 'SHA256:abc'], 'created_at' => now()->addSeconds(2)]);
    ScreenRecords::place('Gone', ['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'created_at' => now()->addSeconds(3)]);

    $places = ($this->data)()['places'];

    expect(array_column($places, 'name'))->toBe(['This server', 'Bucket', 'Box'])
        ->and(array_column($places, 'kindLabel'))->toBe(['A folder on this server', 'S3-compatible storage', 'An SFTP server'])
        ->and(array_column($places, 'where'))->toBe(['/srv/teme-backups', 'https://s3.example.com/teme-backups/site', 'teme@backups.example.com:2222/srv/teme'])
        ->and(array_column($places, 'offServer'))->toBe([false, true, true])
        ->and(array_column($places, 'stateLabel'))->toBe(['Ready', 'Ready', 'Ready'])
        ->and(json_encode(($this->data)()))->not->toContain('AKIATEST')
        ->not->toContain('test-secret')
        ->not->toContain('test-private-key');
});

it('gives each place its newest backup, a copy counted by the backup it copied', function (): void {
    $folder = ScreenRecords::place();
    $offSite = ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);
    $backup = ScreenRecords::backup($folder, '2026-10-20 01:05');
    BackupRecords::run(['kind' => BackupRunKind::Copy, 'destination_id' => $offSite->id, 'parent_id' => $backup->id, 'started_at' => CarbonImmutable::parse('2026-10-20 01:20', 'UTC'), 'ended_at' => CarbonImmutable::parse('2026-10-20 01:40', 'UTC')]);
    $folder->forceFill(['last_answered_at' => CarbonImmutable::parse('2026-10-20 01:05', 'UTC')])->save();

    $places = collect(($this->data)()['places'])->keyBy('name');

    expect($places['This server']['newest'])->toBe('today at 02:05')
        ->and($places['Off-site']['newest'])->toBe('today at 02:05')
        ->and($places['This server']['lastAnswered'])->toBe('today at 02:05')
        ->and($places['Off-site']['lastAnswered'])->toBeNull();
});

it('names the keys in use, and which places each doesn’t open yet', function (): void {
    $folder = ScreenRecords::place();
    BackupRecords::key($folder, ['role' => BackupKeyRole::Recovery, 'label' => 'Sam’s key']);
    $offSite = ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);

    $data = ($this->data)();

    expect($data['keys'])->toBe([
        ['label' => 'Recovery key', 'missingAt' => []],
        ['label' => 'Sam’s key', 'missingAt' => ['Off-site']],
    ])
        ->and(collect($data['places'])->firstWhere('name', 'Off-site')['missingKeys'])->toBe(['Sam’s key'])
        ->and(collect($data['places'])->firstWhere('name', 'This server')['missingKeys'])->toBe([]);
});

it('lists the newest check of each kind at each place, newest first, each in words', function (): void {
    $folder = ScreenRecords::place();
    $backup = ScreenRecords::backup($folder, '2026-10-20 01:05');
    ScreenRecords::check(BackupRunKind::Check, $folder, '2026-10-19 01:11');
    ScreenRecords::check(BackupRunKind::Check, $folder, '2026-10-20 01:11', parent: $backup);
    ScreenRecords::check(BackupRunKind::ReadBack, $folder, '2026-10-20 03:00');
    ScreenRecords::check(BackupRunKind::TestRestore, null, '2026-10-18 04:00', passed: false, message: 'The weekly test restore couldn’t reach teme_verify.');

    $checks = ($this->data)()['checks'];

    expect(array_map(fn (array $check): array => array_diff_key($check, ['id' => true]), $checks))->toBe([
        ['kind' => 'read-back', 'kindLabel' => 'The nightly read-back', 'place' => 'This server', 'passed' => true, 'sentence' => 'A seventh of the backups was read back, and every part matched.', 'when' => 'today at 04:00'],
        ['kind' => 'check', 'kindLabel' => 'The check after the backup', 'place' => 'This server', 'passed' => true, 'sentence' => 'The backup is all there, reads back as written, and has no damage.', 'when' => 'today at 02:11'],
        ['kind' => 'test-restore', 'kindLabel' => 'The weekly test restore', 'place' => null, 'passed' => false, 'sentence' => 'The weekly test restore couldn’t reach teme_verify.', 'when' => 'on 18 October at 05:00'],
    ])->and($checks[0]['id'])->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');
});

it('lists a failed read-back once, as the place’s row with its sentence, not as the whole run as well', function (): void {
    $folder = ScreenRecords::place();
    $sentence = 'The nightly read-back couldn’t read This server.';
    $whole = ScreenRecords::check(BackupRunKind::ReadBack, null, '2026-10-20 03:00', passed: false, message: $sentence);
    ScreenRecords::check(BackupRunKind::ReadBack, $folder, '2026-10-20 03:00', passed: false, message: $sentence, parent: $whole);

    $checks = ($this->data)()['checks'];

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toMatchArray(['kind' => 'read-back', 'place' => 'This server', 'passed' => false, 'sentence' => $sentence]);
});

it('lists a read-back that stopped before any place as the whole run’s one row', function (): void {
    ScreenRecords::place();
    ScreenRecords::check(BackupRunKind::ReadBack, null, '2026-10-20 03:00', passed: false, message: 'The nightly read-back stopped: no place is in use.');

    $checks = ($this->data)()['checks'];

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toMatchArray(['kind' => 'read-back', 'place' => null, 'passed' => false, 'sentence' => 'The nightly read-back stopped: no place is in use.']);
});

it('gives the newest backup’s figures, and the hour and what’s kept in words', function (): void {
    // 03:00 in Lagos is 02:00 UTC: this backup is tonight's, so the next is tomorrow's.
    ScreenRecords::backup(ScreenRecords::place(), '2026-10-20 02:05', figures: ['seconds' => 845, 'database_bytes' => 52_000_000, 'media_files' => 1_204, 'media_bytes' => 3_400_000_000, 'bytes_added' => 38_000_000]);
    ScreenRecords::settings([BackupSettings::GROUP => ['hour' => '03:00', 'keep_weekly' => 0]]);

    $data = ($this->data)();

    expect($data['newest'])->toBe(['when' => 'today at 03:05', 'databaseBytes' => 52_000_000, 'mediaFiles' => 1_204, 'mediaBytes' => 3_400_000_000, 'bytesAdded' => 38_000_000, 'took' => '14 minutes'])
        ->and($data['schedule'])->toMatchArray([
            'hour' => '03:00',
            'keepDaily' => 7,
            'keepWeekly' => 0,
            'keepMonthly' => 12,
            'next' => 'Next backup tomorrow at 03:00.',
            'kept' => 'Keeps a backup from each of the last 7 days, and one from each of 12 months before them.',
            'zone' => 'Africa/Lagos',
            'version' => 1,
        ])
        ->and($data['schedule']['hours'])->toHaveCount(24)
        ->and($data['schedule']['hours'][0])->toBe('00:00');
});

it('has no schedule, places or checks before backups are set up', function (): void {
    expect(($this->data)())->toMatchArray([
        'newest' => null,
        'schedule' => null,
        'places' => [],
        'checks' => [],
        'keys' => [],
        'pending' => null,
    ])->and(($this->data)()['setup']['state'])->toBe('none');
});

it('offers to finish a key while its pending rows are there, and only then', function (): void {
    $place = ScreenRecords::place();
    $resticId = str_repeat('ab', 32);
    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Sam’s key', 'restic_key_id' => $resticId, 'added_at' => now(), 'removed_at' => now()]);
    $challenge = ['purpose' => 'add', 'label' => 'Sam’s key', 'positions' => [3, 7], 'digest' => str_repeat('0', 64), 'pending' => [$place->id => $resticId]];

    expect(($this->data)($challenge)['pending'])->toBe(['purpose' => 'add', 'label' => 'Sam’s key', 'positions' => [3, 7], 'challengeId' => substr(hash('sha256', (string) json_encode([$place->id => $resticId])), 0, 16)])
        ->and(($this->data)(['nonsense' => true])['pending'])->toBeNull();

    BackupKey::query()->where('restic_key_id', $resticId)->update(['removed_at' => null]);

    expect(($this->data)($challenge)['pending'])->toBeNull();
});

it('advises a place off this server only while none is in use, and tells a site with no backup how to make one', function (): void {
    ScreenRecords::place();

    expect(($this->data)()['offServerAdvice'])->toBeTrue()
        // The verdict says when the first backup starts; this line keeps the command alone.
        ->and(($this->data)()['firstBackup'])->toBe('Or press Back up now to make one within a minute.');

    ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);

    expect(($this->data)()['offServerAdvice'])->toBeFalse();
});

it('names the same time in the verdict’s caution and the schedule on a site just set up', function (): void {
    ScreenRecords::place();

    $data = ($this->data)();

    expect($data['verdict']['sentence'])->toBe('No backup has finished yet. The first starts within a minute.')
        ->and($data['schedule']['next'])->toBe('Next backup within a minute.');
});

it('says a first backup is on its way only while one is running or queued, or due and not yet started', function (): void {
    $screen = app(BackupsScreen::class);
    $onItsWay = fn (?array $job = null): bool => $screen->firstBackupOnItsWay($screen->setupState(), $screen->running($this->now), $job, $this->now);
    $place = ScreenRecords::place();

    // None started, and the hour has come: due within a minute.
    expect($onItsWay())->toBeTrue()
        // A job waiting for the scheduler.
        ->and($onItsWay(['state' => 'waiting']))->toBeTrue();

    // One running.
    $run = ScreenRecords::backup($place, '2026-10-20 08:59', BackupRunState::Running);
    expect($onItsWay())->toBeTrue();

    // The first failed: the page shows what it shows for a failed backup, and asks no more.
    $run->forceFill(['state' => BackupRunState::Failed, 'ended_at' => $this->now])->save();
    expect($onItsWay())->toBeFalse()
        ->and($onItsWay(['state' => 'finished']))->toBeFalse();

    // One finished.
    ScreenRecords::backup($place, '2026-10-20 08:00');
    expect($onItsWay())->toBeFalse();
});

it('says no first backup is on its way while backups are paused, or its hour is hours off', function (): void {
    $screen = app(BackupsScreen::class);
    $place = ScreenRecords::place('This server', ['paused_at' => $this->now]);

    expect($screen->firstBackupOnItsWay($screen->setupState(), null, null, $this->now))->toBeFalse();

    $place->forceFill(['paused_at' => null])->save();
    // 02:00 in Lagos is 01:00 UTC, so a backup that started at 01:05 UTC leaves the next a day off, and a failed one is no first backup either.
    ScreenRecords::backup($place, '2026-10-20 01:05', BackupRunState::Failed);

    expect($screen->firstBackupOnItsWay($screen->setupState(), null, null, $this->now))->toBeFalse();
});
