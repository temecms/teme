<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Models\BackupDestination;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\FakeKeyscan;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.backups.cache_path' => '']);
    FixedRecoveryKeys::bind([3, 7]);
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
    $this->begin = fn (array $form = []) => ($this->as)()->post('/admin/tools/backups/set-up', [
        'kind' => 'sftp', 'name' => 'Off-site', 'host' => 'backup.example.com', 'port' => '22', 'user' => 'teme', 'folder' => '/srv/teme', 'label' => 'Recovery key', ...$form,
    ]);
});

it('reads the server’s fingerprint and makes a key pair, keeping the private half encrypted in the session', function (): void {
    $hostKey = FakeKeyscan::answering();

    ($this->begin)()->assertRedirect(route('tools.backups.sftp'));

    $step = session('backups.sftp');

    expect($step['purpose'])->toBe('set-up')
        ->and($step['host_key'])->toBe($hostKey->line())
        ->and($step['fingerprint'])->toBe($hostKey->fingerprint())
        ->and($step['public_key'])->toStartWith('ssh-ed25519 ')
        ->and($step['private_key'])->not->toContain('OPENSSH')
        ->and(Crypt::decryptString($step['private_key']))->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and(json_encode(session()->all()))->not->toContain('PRIVATE KEY');
});

it('shows the public key and the fingerprint, and nothing else of the step', function (): void {
    $hostKey = FakeKeyscan::answering();
    ($this->begin)();

    $response = ($this->as)()->get('/admin/tools/backups/sftp')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tools/backups/sftp')
        ->where('purpose', 'set-up')
        ->where('placeId', null)
        ->where('name', 'Off-site')
        ->where('host', 'backup.example.com')
        ->where('port', 22)
        ->where('user', 'teme')
        ->where('path', '/srv/teme')
        ->where('publicKey', session('backups.sftp.public_key'))
        ->where('fingerprint', $hostKey->fingerprint()));

    expect(array_keys($response->viewData('page')['props']))->not->toContain('private_key')
        ->and(json_encode($response->viewData('page')))->not->toContain(session('backups.sftp.private_key'));
});

it('refuses to go on until the key is added and the fingerprint checked', function (): void {
    FakeKeyscan::answering();
    ($this->begin)();

    ($this->as)()->post('/admin/tools/backups/sftp', [])
        ->assertSessionHasErrors(['confirmed' => 'Add the key and check the fingerprint first.']);

    expect(BackupDestination::query()->count())->toBe(0);
});

it('sets backups up at the SFTP server through the scripted restic, then forgets the step', function (): void {
    $hostKey = FakeKeyscan::answering();
    ($this->begin)();
    $privateKey = Crypt::decryptString(session('backups.sftp.private_key'));
    WatchedProcesses::asRestic();

    ($this->as)()->post('/admin/tools/backups/sftp', ['confirmed' => '1'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/recovery-key')->where('places', ['Off-site']));

    $place = BackupDestination::query()->where('name', 'Off-site')->firstOrFail();

    expect($place->kind)->toBe(BackupDestinationKind::Sftp)
        ->and($place->location['host_key'])->toBe($hostKey->line())
        ->and($place->credentials['private_key'])->toBe($privateKey)
        ->and(session()->has('backups.sftp'))->toBeFalse();
});

it('forgets an SFTP step after an hour, and when another place form is sent', function (): void {
    FakeKeyscan::answering();
    ($this->begin)();
    $this->travel(61)->minutes();

    ($this->as)()->get('/admin/tools/backups/sftp')
        ->assertRedirect(route('tools.backups.setup'))
        ->assertInertiaFlash('toast.message', 'That step has expired. Start again.');

    $this->travelBack();
    ($this->begin)();
    ($this->as)()->post('/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => public_path('backups')]);

    expect(session()->has('backups.sftp'))->toBeFalse();
});

it('refuses a host or a user that would add a line to ssh’s configuration', function (): void {
    FakeKeyscan::answering();

    ($this->begin)(['host' => "backup.example.com\nProxyCommand touch /tmp/owned"])
        ->assertSessionHasErrors(['host' => 'Give the server’s name or IP address, such as backup.example.com.']);

    // Refused before ssh-keyscan ran.
    Process::assertNothingRan();

    ($this->begin)(['user' => 'teme -oProxyCommand=x'])
        ->assertSessionHasErrors(['user' => 'Give the user Teme signs in as: letters, digits, dots, hyphens and underscores.']);

    expect(session()->has('backups.sftp'))->toBeFalse();
});

it('asks for the password before reading an SFTP server’s fingerprint', function (): void {
    FakeKeyscan::answering();

    $this->actingAs($this->admin)->post('/admin/tools/backups/set-up', ['kind' => 'sftp', 'name' => 'Off-site', 'host' => 'backup.example.com', 'port' => '22', 'user' => 'teme', 'folder' => '/srv/teme'])
        ->assertRedirect(route('password.confirm'));

    expect(session()->has('backups.sftp'))->toBeFalse();

    Process::assertNothingRan();
});

it('starts again by forgetting the step', function (): void {
    FakeKeyscan::answering();
    ($this->begin)();

    ($this->as)()->delete('/admin/tools/backups/sftp')->assertRedirect(route('tools.backups.setup'));

    expect(session()->has('backups.sftp'))->toBeFalse();
});
