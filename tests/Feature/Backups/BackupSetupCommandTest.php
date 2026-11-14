<?php

declare(strict_types=1);

use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Queries\BackupSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folder = TemporaryRepository::freshFolder();
});

afterEach(function (): void {
    TemporaryRepository::remove($this->folder);
});

const BSC_KINDS = ['A folder on this server', 'S3-compatible storage', 'An SFTP server'];

it('sets backups up at a folder, shows the key once, and takes two groups back (I-2401)', function (): void {
    FixedRecoveryKeys::bind([3, 7]);

    $this->artisan('teme:backup:setup')
        ->expectsChoice('Where should backups be kept?', 'A folder on this server', BSC_KINDS)
        ->expectsQuestion('Name this place', 'This server')
        ->expectsQuestion('Folder', $this->folder)
        ->expectsOutputToContain('Your recovery key')
        ->expectsOutputToContain('  '.FixedRecoveryKeys::KEY)
        ->expectsOutputToContain('Write it down or print it, and keep it somewhere other than this server. Teme keeps no copy.')
        ->expectsQuestion('Type group 3 of your recovery key', '9htr')
        ->expectsQuestion('Type group 7 of your recovery key', 'YA8G')
        ->expectsOutputToContain('Backups are set up at This server.')
        ->expectsOutputToContain('This server is on this server, so a backup won’t survive losing it.')
        ->assertExitCode(0);

    expect(app(BackupSetup::class)->isFinished())->toBeTrue();
});

it('refuses to run without a person at the terminal', function (): void {
    $this->artisan('teme:backup:setup', ['--no-interaction' => true])
        ->expectsOutputToContain('teme:backup:setup shows a recovery key once and asks for part of it back, so a person must run it at a terminal.')
        ->assertExitCode(1);

    expect(BackupDestination::query()->count())->toBe(0);
});

it('offers a new key when setup wasn’t finished', function (): void {
    ReadyBackups::setUp(NewPlace::folder('This server', $this->folder));
    FixedRecoveryKeys::bind([2, 5]);

    $this->artisan('teme:backup:setup')
        ->expectsOutputToContain('Backups are being set up at This server, but the recovery key wasn’t confirmed.')
        ->expectsConfirmation('Show a new recovery key for This server?', 'yes')
        ->expectsOutputToContain('  '.FixedRecoveryKeys::KEY)
        ->expectsQuestion('Type group 2 of your recovery key', '2VXD')
        ->expectsQuestion('Type group 5 of your recovery key', 'WP6E')
        ->expectsOutputToContain('Backups are set up at This server.')
        ->assertExitCode(0);

    expect(app(BackupSetup::class)->isFinished())->toBeTrue();
});

it('gives three tries at the groups, then stops without finishing', function (): void {
    FixedRecoveryKeys::bind([3, 7]);

    $this->artisan('teme:backup:setup')
        ->expectsChoice('Where should backups be kept?', 'A folder on this server', BSC_KINDS)
        ->expectsQuestion('Name this place', 'This server')
        ->expectsQuestion('Folder', $this->folder)
        ->expectsQuestion('Type group 3 of your recovery key', 'AAAA')
        ->expectsQuestion('Type group 7 of your recovery key', 'AAAA')
        ->expectsQuestion('Type group 3 of your recovery key', 'BBBB')
        ->expectsQuestion('Type group 7 of your recovery key', 'BBBB')
        ->expectsQuestion('Type group 3 of your recovery key', 'CCCC')
        ->expectsQuestion('Type group 7 of your recovery key', 'CCCC')
        ->expectsOutputToContain('Those aren’t groups 3 and 7 of the recovery key.')
        ->expectsOutputToContain('Not confirmed. Run php artisan teme:backup:setup again to see a new key.')
        ->assertExitCode(1);

    expect(app(BackupSetup::class)->isFinished())->toBeFalse();
});
