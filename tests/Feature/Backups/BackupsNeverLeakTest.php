<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Backups\FakeKeyscan;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.backups.cache_path' => '']);
    FixedRecoveryKeys::bind([3, 7]);
    $this->folder = TemporaryRepository::freshFolder();
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
    // Every group of the key, as printed and as a person might type it.
    $this->secrets = [FixedRecoveryKeys::KEY, str_replace('-', '', FixedRecoveryKeys::KEY), ...explode('-', FixedRecoveryKeys::KEY)];
});

afterEach(fn () => TemporaryRepository::remove($this->folder));

it('never keeps a new key, a typed group or a credential in the session, a flash, a log or a later page', function (): void {
    ($this->as)()->post('/admin/tools/backups/set-up', ['kind' => 's3', 'name' => 'Off-site', 'endpoint' => 'http://refused.example.com', 'bucket' => 'teme', 'access_key_id' => 'AKIA-LEAK-CHECK', 'secret_access_key' => 'leak-check-secret']);
    ($this->as)()->post('/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder])->assertOk();
    $challenge = substr(hash('sha256', (string) json_encode(session('backups.challenge.pending'))), 0, 16);
    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => '9htr', 7 => 'ya8g'], 'challenge' => $challenge])->assertOk();

    $later = ($this->as)()->get('/admin/tools/backups')->getContent().($this->as)()->get('/admin/tools')->getContent();
    $everything = json_encode(session()->all()).implode("\n", $this->logged).$later;

    foreach ([...$this->secrets, '9htr', 'ya8g', 'AKIA-LEAK-CHECK', 'leak-check-secret'] as $secret) {
        expect($everything)->not->toContain($secret);
    }
});

it('keeps an SFTP private key only encrypted, and only until its step ends', function (): void {
    FakeKeyscan::answering();
    ($this->as)()->post('/admin/tools/backups/set-up', ['kind' => 'sftp', 'name' => 'Off-site', 'host' => 'backup.example.com', 'port' => '22', 'user' => 'teme', 'folder' => '/srv/teme']);

    $page = ($this->as)()->get('/admin/tools/backups/sftp')->getContent();

    expect(json_encode(session()->all()))->not->toContain('OPENSSH')
        ->and($page)->not->toContain('OPENSSH')
        ->and($page)->not->toContain((string) session('backups.sftp.private_key'));

    WatchedProcesses::asRestic();
    ($this->as)()->post('/admin/tools/backups/sftp', ['confirmed' => '1'])->assertOk();

    expect(session()->has('backups.sftp'))->toBeFalse()
        ->and(implode("\n", $this->logged))->not->toContain('OPENSSH');
});

it('shows the key only in the answer to the request that made it', function (): void {
    $answer = ($this->as)()->post('/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder]);

    expect($answer->getContent())->toContain('9HTR')
        ->and($answer->headers->get('Cache-Control'))->toContain('no-store');

    foreach (['/admin/tools/backups', '/admin/tools/backups/set-up', '/admin/tools'] as $address) {
        $response = ($this->as)()->get($address);
        $content = $response->isRedirect() ? ($this->as)()->get($response->headers->get('Location'))->getContent() : $response->getContent();

        expect($content)->not->toContain('9HTR')->not->toContain('YA8G');
    }
});

it('never sends a place’s credentials to any page of the screen', function (): void {
    ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3]);
    ScreenRecords::place('Box', ['kind' => BackupDestinationKind::Sftp, 'created_at' => now()->addSecond()]);

    $content = ($this->as)()->get('/admin/tools/backups')->getContent().($this->as)()->get('/admin/tools/backups/set-up')->getContent();

    expect($content)->not->toContain('AKIATEST')->not->toContain('test-secret')->not->toContain('test-private-key');
});

it('never keeps a key typed to add a place, check or extend, in the session, the old input or a log', function (): void {
    $ready = ReadyBackups::atFolder();
    $typed = $ready->recoveryKey->value();

    try {
        ($this->as)()->postJson('/admin/tools/backups/keys/check', ['recovery_key' => strtolower($typed)])->assertOk();
        ($this->as)()->post('/admin/tools/backups/keys/extend', ['recovery_key' => $typed]);
        ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => public_path('refused'), 'recovery_key' => $typed]);

        $everything = json_encode(session()->all()).implode("\n", $this->logged).($this->as)()->get('/admin/tools/backups')->getContent();

        expect($everything)->not->toContain($typed)->not->toContain(strtolower($typed));
    } finally {
        $ready->delete();
    }
});

it('never sends a place’s credentials to its change page', function (): void {
    ScreenRecords::place();
    $bucket = ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);

    $content = ($this->as)()->get("/admin/tools/backups/places/{$bucket->id}/edit")->getContent();

    expect($content)->not->toContain('AKIATEST')->not->toContain('test-secret');
});
