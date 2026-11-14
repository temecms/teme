<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\FakeKeyscan;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.backups.cache_path' => '']);
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
    $this->folders = [];
    $this->fresh = function (): string {
        return $this->folders[] = TemporaryRepository::freshFolder();
    };
});

afterEach(function (): void {
    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

it('asks for the password before the add page, then draws it', function (): void {
    ScreenRecords::place();

    $this->actingAs($this->admin)->get('/admin/tools/backups/places/new')->assertRedirect(route('password.confirm'));

    ($this->as)()->get('/admin/tools/backups/places/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/place')->where('mode', 'add')->where('place', null));
});

it('adds a folder place with the recovery key typed once, and forgets the key', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;

    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => ($this->fresh)(), 'recovery_key' => strtolower($ready->recoveryKey->value())])
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Added Second place.');

    expect(BackupDestination::query()->where('name', 'Second place')->exists())->toBeTrue()
        ->and(json_encode(session()->all()))->not->toContain($ready->recoveryKey->value());
});

it('refuses a key that doesn’t open the backups, under its field, adding nothing', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;

    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => ($this->fresh)(), 'recovery_key' => '0000-0000-0000-0000-0000-0000-0000-0000'])
        ->assertSessionHasErrors(['recovery_key' => 'That recovery key doesn’t open the backups at This server.']);

    expect(BackupDestination::query()->where('name', 'Second place')->exists())->toBeFalse()
        ->and(session('_old_input'))->not->toHaveKey('recovery_key');
});

it('refuses what isn’t a recovery key before running anything', function (): void {
    ScreenRecords::place();

    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => ($this->fresh)(), 'recovery_key' => 'not a key'])
        ->assertSessionHasErrors(['recovery_key' => 'A recovery key is eight groups of four letters and digits, separated by hyphens.']);
});

it('takes an SFTP place through its second step, asking for the recovery key there', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;
    // A closed local port: ssh fails at once, so restic refuses with words and nothing reaches a network.
    FakeKeyscan::answering('127.0.0.1');

    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'sftp', 'name' => 'Box', 'host' => '127.0.0.1', 'port' => '9', 'user' => 'teme', 'folder' => '/srv/teme'])
        ->assertRedirect(route('tools.backups.sftp'));

    expect(session('backups.sftp.purpose'))->toBe('add');

    ($this->as)()->post('/admin/tools/backups/sftp', ['confirmed' => '1', 'recovery_key' => $ready->recoveryKey->value()])
        ->assertSessionHasErrors('place');

    expect(BackupDestination::query()->where('name', 'Box')->exists())->toBeFalse()
        ->and(session()->has('backups.sftp'))->toBeTrue();
});

it('changes a place’s name', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;

    ($this->as)()->put("/admin/tools/backups/places/{$ready->place->id}", ['name' => 'The server', 'path' => $ready->folder])
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Changed The server.');

    expect($ready->place->fresh()->name)->toBe('The server');
});

it('changes S3 keys only when both are given', function (): void {
    ScreenRecords::place();
    $bucket = ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);

    ($this->as)()->put("/admin/tools/backups/places/{$bucket->id}", ['name' => 'Off-site', 'endpoint' => 'https://s3.example.com', 'bucket' => 'teme-backups', 'folder' => 'site', 'secret_access_key' => 'only-the-secret'])
        ->assertSessionHasErrors(['secret_access_key' => 'Give both keys to change them, or leave both empty to keep them.']);

    expect($bucket->fresh()->credentials)->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'test-secret']);
});

it('draws the change page without a credential, and an SFTP place with its public key', function (): void {
    ScreenRecords::place();
    $pair = SftpKeyPair::generate();
    $box = ScreenRecords::place('Box', ['kind' => BackupDestinationKind::Sftp, 'credentials' => ['private_key' => $pair->privateKey()], 'location' => ['host' => 'backups.example.com', 'port' => 22, 'user' => 'teme', 'path' => '/srv/teme', 'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOnlyForTheTestOnlyForTheTestOnlyForTheTe', 'fingerprint' => 'SHA256:x'], 'created_at' => now()->addSecond()]);

    $response = ($this->as)()->get("/admin/tools/backups/places/{$box->id}/edit")->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->where('mode', 'change')->where('place.host', 'backups.example.com')->where('place.publicKey', $pair->publicKey()));

    expect($response->getContent())->not->toContain('OPENSSH');
});

it('takes a changed SFTP server through the fingerprint step, keeping the place’s pair', function (): void {
    ScreenRecords::place();
    $pair = SftpKeyPair::generate();
    $box = ScreenRecords::place('Box', ['kind' => BackupDestinationKind::Sftp, 'credentials' => ['private_key' => $pair->privateKey()], 'location' => ['host' => 'old.example.com', 'port' => 22, 'user' => 'teme', 'path' => '/srv/teme', 'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOnlyForTheTestOnlyForTheTestOnlyForTheTe', 'fingerprint' => 'SHA256:x'], 'created_at' => now()->addSecond()]);
    FakeKeyscan::answering('new.example.com');

    ($this->as)()->put("/admin/tools/backups/places/{$box->id}", ['name' => 'Box', 'host' => 'new.example.com', 'port' => '22', 'user' => 'teme', 'folder' => '/srv/teme'])
        ->assertRedirect(route('tools.backups.sftp'));

    expect(session('backups.sftp'))->toMatchArray(['purpose' => 'change', 'place_id' => $box->id, 'public_key' => $pair->publicKey(), 'private_key' => null])
        ->and($box->fresh()->location['host'])->toBe('old.example.com');
});

it('measures what a place holds with the real restic, writing nothing', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;

    ($this->as)()->postJson("/admin/tools/backups/places/{$ready->place->id}/storage")
        ->assertOk()
        ->assertJsonPath('sentence', null)
        ->assertJson(fn ($json) => $json->whereType('bytes', 'integer')->etc());
});

it('leads a gone place’s change page to the screen with the words, and an add step’s Start again to the add page', function (): void {
    ScreenRecords::place();

    ($this->as)()->get('/admin/tools/backups/places/01hzzzzzzzzzzzzzzzzzzzzzzz/edit')
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'There’s no such place.');

    FakeKeyscan::answering('127.0.0.1');
    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'sftp', 'name' => 'Box', 'host' => '127.0.0.1', 'port' => '9', 'user' => 'teme', 'folder' => '/srv/teme']);

    ($this->as)()->delete('/admin/tools/backups/sftp')->assertRedirect(route('tools.backups.places.create'));

    expect(session()->has('backups.sftp'))->toBeFalse();
});

it('refuses every place route to everyone but an administrator', function (): void {
    $place = ScreenRecords::place();
    $editor = User::factory()->create()->assignRole('editor');

    foreach ([['get', '/admin/tools/backups/places/new'], ['post', '/admin/tools/backups/places'], ['get', "/admin/tools/backups/places/{$place->id}/edit"], ['put', "/admin/tools/backups/places/{$place->id}"], ['post', "/admin/tools/backups/places/{$place->id}/storage"]] as [$method, $address]) {
        $this->actingAs($editor)->withSession(scConfirmed())->{$method}($address)->assertForbidden();
    }

    expect(BackupDestination::query()->count())->toBe(1);
});

it('asks for the password before adding, writing nothing', function (): void {
    $ready = ReadyBackups::atFolder();
    $this->folders[] = $ready->folder;

    $this->actingAs($this->admin)->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => ($this->fresh)(), 'recovery_key' => $ready->recoveryKey->value()])
        ->assertRedirect(route('password.confirm'));

    expect(BackupDestination::query()->where('name', 'Second place')->exists())->toBeFalse();
});
