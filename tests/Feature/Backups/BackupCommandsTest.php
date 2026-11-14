<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Models\BackupDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ready = ReadyBackups::atFolder();
    $this->second = TemporaryRepository::freshFolder();
});

afterEach(function (): void {
    TemporaryRepository::remove($this->ready->folder);
    TemporaryRepository::remove($this->second);
});

it('lists the places, their state and the keys that open them', function (): void {
    $this->artisan('teme:backup:destinations')
        ->expectsTable(['Name', 'Kind', 'Where', 'State', 'Last answered', 'Keys'], [[
            'This server', 'Folder', $this->ready->folder, 'Ready',
            $this->ready->place->last_answered_at?->toDateTimeString() ?? '', 'Recovery key',
        ]])
        ->assertExitCode(0);
});

it('adds, tests and removes a place at the terminal, asking for the recovery key once (I-2402)', function (): void {
    $this->artisan('teme:backup:destinations', ['action' => 'add'])
        ->expectsChoice('Where should backups be kept?', 'A folder on this server', ['A folder on this server', 'S3-compatible storage', 'An SFTP server'])
        ->expectsQuestion('Name this place', 'Second place')
        ->expectsQuestion('Folder', $this->second)
        ->expectsQuestion('Your recovery key, to lock the new place with it too', $this->ready->recoveryKey->value())
        ->expectsOutputToContain('Added Second place.')
        ->assertExitCode(0);

    $this->artisan('teme:backup:destinations', ['action' => 'test', 'place' => 'second place'])
        ->expectsOutputToContain('Second place answered, and Teme’s key opened its backups.')
        ->assertExitCode(0);

    $this->artisan('teme:backup:destinations', ['action' => 'remove', 'place' => 'Second place'])
        ->expectsConfirmation('Teme will stop using Second place. The backups already there stay there, and only a recovery key opens them. Remove it?', 'yes')
        ->expectsOutputToContain('Removed Second place. Its backups are still there.')
        ->assertExitCode(0);

    expect(BackupDestination::query()->where('name', 'Second place')->first()?->state)->toBe(BackupDestinationState::Removed);
});

it('checks a recovery key at the terminal', function (): void {
    $this->artisan('teme:backup:key', ['action' => 'check'])
        ->expectsQuestion('Your recovery key', strtolower($this->ready->recoveryKey->value()))
        ->expectsOutputToContain('It is Recovery key.')
        ->expectsOutputToContain('Opens the backups at This server.')
        ->assertExitCode(0);
});

it('replaces a recovery key at the terminal, typing two groups of the new one back', function (): void {
    FixedRecoveryKeys::bind([2, 6]);

    $this->artisan('teme:backup:key', ['action' => 'replace', 'label' => 'Recovery key'])
        ->expectsConfirmation('Teme will make a new key for Recovery key. The old one keeps working until you’ve typed part of the new one back. Go on?', 'yes')
        ->expectsOutputToContain('  '.FixedRecoveryKeys::KEY)
        ->expectsQuestion('Type group 2 of your recovery key', '2VXD')
        ->expectsQuestion('Type group 6 of your recovery key', 'JF3S')
        ->expectsOutputToContain('Recovery key is replaced: the old key no longer opens the backups.')
        ->assertExitCode(0);
});

it('refuses to show or ask for a key without a person at the terminal', function (string $command, array $arguments): void {
    $this->artisan($command, [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain('This needs a person at the terminal: it asks for a recovery key or a password.')
        ->assertExitCode(1);
})->with([
    'adding a place' => ['teme:backup:destinations', ['action' => 'add']],
    'replacing a key' => ['teme:backup:key', ['action' => 'replace', 'label' => 'Recovery key']],
    'checking a key' => ['teme:backup:key', ['action' => 'check']],
]);

it('refuses an action or a place it doesn’t know', function (): void {
    $this->artisan('teme:backup:destinations', ['action' => 'frobnicate'])->expectsOutputToContain('Choose list, add, change, test or remove.')->assertExitCode(1);
    $this->artisan('teme:backup:destinations', ['action' => 'test', 'place' => 'Nowhere'])->expectsOutputToContain('There’s no place called Nowhere in use.')->assertExitCode(1);
    $this->artisan('teme:backup:key', ['action' => 'frobnicate'])->expectsOutputToContain('Choose check, add, extend or replace.')->assertExitCode(1);
});
