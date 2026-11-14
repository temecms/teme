<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupKey;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.backups.cache_path' => '']);
    $this->ready = ReadyBackups::atFolder();
    // Keys made from here on are FixedRecoveryKeys::KEY; the setup's was random.
    FixedRecoveryKeys::bind([3, 7]);
    $this->groups = explode('-', FixedRecoveryKeys::KEY);
    $this->folders = [];
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
    $this->confirm = fn () => ($this->as)()->postJson('/admin/tools/backups/key/confirm', [
        'groups' => [3 => $this->groups[2], 7 => $this->groups[6]],
        'challenge' => substr(hash('sha256', (string) json_encode(session('backups.challenge.pending'))), 0, 16),
    ]);
});

afterEach(function (): void {
    $this->ready->delete();

    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

it('asks for the password before the new key page, then draws it', function (): void {
    $this->actingAs($this->admin)->get('/admin/tools/backups/keys/new')->assertRedirect(route('password.confirm'));
    ($this->as)()->get('/admin/tools/backups/keys/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tools/backups/new-key'));
});

it('adds a key under its label, shows it once, and records it once two groups are typed back', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys', ['label' => 'Sam’s key'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/recovery-key')->where('purpose', 'add')->where('label', 'Sam’s key')->where('groups', $this->groups));

    ($this->confirm)()->assertOk();

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.message', 'Sam’s key now opens the backups at every place.')
            ->where('keys.1.label', 'Sam’s key'));
});

it('refuses a label already taken, under its field', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys', ['label' => 'recovery KEY'])
        ->assertSessionHasErrors(['label' => 'Another recovery key is already called recovery KEY.']);
});

it('replaces a key, keeping the old one until the new one is typed back', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys', ['label' => 'Recovery key', 'replace' => '1'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('purpose', 'replace'));

    expect(BackupKey::query()->where('role', BackupKeyRole::Recovery->value)->whereNull('removed_at')->count())->toBe(1);

    ($this->confirm)()->assertOk();

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->hasFlash('toast.message', 'Replaced Recovery key. The old key no longer opens the backups.'));
});

it('leads the keys’ own address to the screen, never to a key', function (): void {
    ($this->as)()->get('/admin/tools/backups/keys')->assertRedirect(route('tools.backups.index'));
});

it('checks a recovery key, place by place, keeping nothing of it', function (): void {
    ($this->as)()->postJson('/admin/tools/backups/keys/check', ['recovery_key' => $this->ready->recoveryKey->value()])
        ->assertOk()
        ->assertExactJson(['label' => 'Recovery key', 'opens' => ['This server'], 'doesNotOpen' => []]);

    expect(json_encode(session()->all()))->not->toContain($this->ready->recoveryKey->value());
});

it('says a key opens nothing when it isn’t one of the site’s', function (): void {
    ($this->as)()->postJson('/admin/tools/backups/keys/check', ['recovery_key' => '0000-0000-0000-0000-0000-0000-0000-0000'])
        ->assertOk()
        ->assertExactJson(['label' => null, 'opens' => [], 'doesNotOpen' => ['This server']]);
});

it('refuses what isn’t a recovery key, and asks for a lapsed password with 423', function (): void {
    // The lapsed request first: a confirmed one leaves its confirmation in the test's session.
    $this->actingAs($this->admin)->postJson('/admin/tools/backups/keys/check', ['recovery_key' => $this->ready->recoveryKey->value()])
        ->assertStatus(423);

    ($this->as)()->postJson('/admin/tools/backups/keys/check', ['recovery_key' => 'nope'])
        ->assertJsonValidationErrors(['recovery_key' => 'A recovery key is eight groups of four letters and digits, separated by hyphens.']);
});

it('adds a key to places added after it, from the key itself', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys', ['label' => 'Sam’s key']);
    ($this->confirm)();
    $second = ReadyBackups::folderPlace();
    $this->folders[] = $second->location()['path'];
    ($this->as)()->post('/admin/tools/backups/places', ['kind' => 'folder', 'name' => 'Second place', 'path' => $second->location()['path'], 'recovery_key' => $this->ready->recoveryKey->value()]);

    ($this->as)()->post('/admin/tools/backups/keys/extend', ['recovery_key' => FixedRecoveryKeys::KEY])
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Sam’s key now opens the backups at Second place too.');
});

it('offers to finish an added key whose groups weren’t typed back', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys', ['label' => 'Sam’s key']);

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.purpose', 'add')
            ->where('pending.label', 'Sam’s key')
            ->where('pending.positions', [3, 7]));
});

it('never puts a typed key back into the old input or a log', function (): void {
    ($this->as)()->post('/admin/tools/backups/keys/extend', ['recovery_key' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ'])
        ->assertSessionHasErrors('recovery_key');

    expect(session('_old_input'))->not->toHaveKey('recovery_key');
});

it('refuses every key route to everyone but an administrator, writing nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = BackupKey::query()->count();

    foreach ([['get', '/admin/tools/backups/keys/new'], ['post', '/admin/tools/backups/keys'], ['post', '/admin/tools/backups/keys/extend'], ['post', '/admin/tools/backups/keys/check']] as [$method, $address]) {
        $this->actingAs($editor)->withSession(scConfirmed())->{$method}($address, ['label' => 'Mine'])->assertForbidden();
    }

    expect(BackupKey::query()->count())->toBe($before);
});
