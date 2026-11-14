<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Queries\BackupSetup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
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
    $this->setUp = fn (array $form = []) => ($this->as)()->post('/admin/tools/backups/set-up', [
        'kind' => 'folder', 'name' => 'This server', 'path' => $this->folder, 'label' => 'Recovery key', ...$form,
    ]);
    $this->groups = explode('-', FixedRecoveryKeys::KEY);
    // What the key page sends back: which key it showed (RecoveryKeyPage::challengeId()).
    $this->challengeId = fn (): string => substr(hash('sha256', (string) json_encode(session('backups.challenge.pending') ?? [])), 0, 16);
});

afterEach(fn () => TemporaryRepository::remove($this->folder));

it('asks for the password before the setup page, by the elevation window, and comes back to it', function (): void {
    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->getTimestamp()])
        ->get('/admin/tools/backups/set-up')
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas('url.intended', url('/admin/tools/backups/set-up'));

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->subMinutes(14)->getTimestamp()])
        ->get('/admin/tools/backups/set-up')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/set-up')->where('suggestedFolder', BackupSetup::suggestedFolder()));
});

it('sends a site already set up, or half set up, to the screen', function (): void {
    ScreenRecords::place(recoveryLabel: null);

    ($this->as)()->get('/admin/tools/backups/set-up')->assertRedirect(route('tools.backups.index'));
});

it('sets backups up at a folder with the real restic, and answers that request with the key, once', function (): void {
    $response = ($this->setUp)()->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tools/backups/recovery-key')
        ->where('purpose', 'set-up')
        ->where('label', 'Recovery key')
        ->where('groups', $this->groups)
        ->where('positions', [3, 7])
        ->where('places', ['This server']));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->viewData('page')['encryptHistory'])->toBeTrue()
        ->and(is_file($this->folder.'/config'))->toBeTrue()
        ->and(BackupDestination::query()->where('name', 'This server')->exists())->toBeTrue()
        ->and(session('backups.challenge.positions'))->toBe([3, 7])
        // The session holds the challenge, never a group of the key.
        ->and(json_encode(session()->all()))->not->toContain($this->groups[0])->not->toContain($this->groups[2])->not->toContain($this->groups[6]);

    // Reloading the page's address shows the screen, never the key.
    ($this->as)()->get('/admin/tools/backups/set-up')->assertRedirect(route('tools.backups.index'));
});

it('takes two groups typed back, records the key, and clears the browser’s history', function (): void {
    ($this->setUp)();

    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => strtolower($this->groups[2]), 7 => $this->groups[6]], 'challenge' => ($this->challengeId)()])
        ->assertOk()
        ->assertJson(['redirect' => '/admin/tools/backups']);

    expect(session()->has('backups.challenge'))->toBeFalse()
        ->and(session('inertia.clear_history'))->toBeTrue()
        ->and(app(BackupSetup::class)->isFinished())->toBeTrue();

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->hasFlash('toast.message', 'Backups are set up at This server. The first backup starts within a minute.'));
});

it('refuses a wrong pair under the groups, keeping the challenge and recording nothing', function (): void {
    ($this->setUp)();

    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => $this->groups[3], 7 => $this->groups[6]], 'challenge' => ($this->challengeId)()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['groups' => 'Those aren’t groups 3 and 7 of the recovery key. Check the key you wrote down, and type them again.']);

    expect(session()->has('backups.challenge'))->toBeTrue()
        ->and(app(BackupSetup::class)->isFinished())->toBeFalse();
});

it('answers a lapsed password on the typed-back step with 423, keeping the challenge', function (): void {
    ($this->setUp)();

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->subHour()->getTimestamp()])
        ->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => $this->groups[2], 7 => $this->groups[6]], 'challenge' => ($this->challengeId)()])
        ->assertStatus(423)
        ->assertJson(['message' => 'Please confirm your password first.']);

    expect(session()->has('backups.challenge'))->toBeTrue()
        ->and(app(BackupSetup::class)->isFinished())->toBeFalse();
});

it('refuses the groups of a key the session no longer waits for', function (): void {
    ($this->setUp)();
    $challenge = ($this->challengeId)();
    session()->forget('backups.challenge');
    session()->save();

    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => $this->groups[2], 7 => $this->groups[6]], 'challenge' => $challenge])
        ->assertJsonValidationErrors(['groups' => 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.']);
});

it('refuses the groups typed in a tab whose key another tab has since replaced', function (): void {
    // The first tab is shown a key; a second tab, on the half-done setup, is shown a newer one.
    $first = ($this->setUp)();
    $firstTab = $first->viewData('page')['props']['challengeId'];
    $second = ($this->as)()->post('/admin/tools/backups/set-up')->assertOk();
    $secondTab = $second->viewData('page')['props']['challengeId'];

    expect($firstTab)->toMatch('/^[0-9a-f]{16}$/')->not->toBe($secondTab);

    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => $this->groups[2], 7 => $this->groups[6]], 'challenge' => $firstTab])
        ->assertJsonValidationErrors(['groups' => 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.']);

    expect(app(BackupSetup::class)->isFinished())->toBeFalse();

    ($this->as)()->postJson('/admin/tools/backups/key/confirm', ['groups' => [3 => $this->groups[2], 7 => $this->groups[6]], 'challenge' => $secondTab])
        ->assertOk();

    expect(app(BackupSetup::class)->isFinished())->toBeTrue();
});

it('offers a new key when setup was left unfinished, and the new one sweeps the old', function (): void {
    ($this->setUp)();
    $first = session('backups.challenge.pending');

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->where('setup.state', 'unfinished')->where('pending.positions', [3, 7]));

    ($this->as)()->post('/admin/tools/backups/set-up')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/recovery-key')->where('places', ['This server']));

    expect(session('backups.challenge.pending'))->not->toBe($first);
});

it('refuses a folder inside the public folder under its field, making nothing', function (): void {
    ($this->setUp)(['path' => public_path('backups')])
        ->assertSessionHasErrors(['path' => 'Backups can’t be kept inside the site’s public folder, where anyone could download them.']);

    expect(BackupDestination::query()->count())->toBe(0);
});

it('never puts a refused form’s keys back into the old input', function (): void {
    ($this->setUp)(['kind' => 's3', 'endpoint' => 'http://not-https.example.com', 'bucket' => 'teme', 'access_key_id' => 'AKIA-TYPED', 'secret_access_key' => 'typed-secret-value'])
        ->assertSessionHasErrors('endpoint');

    expect(session('_old_input'))->not->toHaveKey('secret_access_key')
        ->and(session('_old_input'))->not->toHaveKey('access_key_id')
        ->and(json_encode(session()->all()))->not->toContain('typed-secret-value');
});

it('sets backups up at S3-compatible storage, its keys reaching restic only in the environment', function (): void {
    $watched = WatchedProcesses::asRestic();

    ($this->setUp)(['kind' => 's3', 'name' => 'Off-site', 'endpoint' => 'https://s3.example.com', 'bucket' => 'teme-backups', 'folder' => 'site', 'region' => 'eu-central-1', 'access_key_id' => 'AKIA-TYPED', 'secret_access_key' => 'typed-secret-value'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/backups/recovery-key')->where('places', ['Off-site']));

    $place = BackupDestination::query()->where('name', 'Off-site')->firstOrFail();

    expect($place->kind)->toBe(BackupDestinationKind::S3)
        ->and($place->location)->toMatchArray(['endpoint' => 'https://s3.example.com', 'bucket' => 'teme-backups', 'path' => 'site', 'region' => 'eu-central-1'])
        ->and($watched->restic('init')[0]['environment']['AWS_SECRET_ACCESS_KEY'])->toBe('typed-secret-value')
        ->and($watched->arguments())->not->toContain('typed-secret-value');
});

it('writes nothing and shows no key without the permission or the password', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->post('/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder])->assertRedirect(route('password.confirm'));
    $this->actingAs($editor)->withSession(scConfirmed())->get('/admin/tools/backups/set-up')->assertForbidden();
    $this->actingAs($editor)->withSession(scConfirmed())->post('/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder])->assertForbidden();

    expect(BackupDestination::query()->count())->toBe(0)
        ->and(is_file($this->folder.'/config'))->toBeFalse();
});

it('lets go of a challenge the screen no longer waits for', function (): void {
    ($this->as)()->withSession(['backups.challenge' => ['purpose' => 'add', 'label' => 'Gone', 'positions' => [3, 7], 'digest' => str_repeat('0', 64), 'pending' => ['01jabcdefghjkmnpqrstvwxyz0' => str_repeat('ab', 32)]]])
        ->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->where('pending', null));

    expect(session()->has('backups.challenge'))->toBeFalse();
});

it('refuses to make a recovery key over a plain connection, making nothing', function (): void {
    ($this->as)()->post('http://backups.example.test/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder, 'label' => 'Recovery key'])
        ->assertSessionHasErrors(['place' => 'Set backups up over HTTPS: a recovery key is never sent over a plain connection.']);

    expect(BackupDestination::query()->count())->toBe(0)
        ->and(is_file($this->folder.'/config'))->toBeFalse()
        ->and(session()->has('backups.challenge'))->toBeFalse();

    // The same request over HTTPS is let through.
    ($this->as)()->post('https://backups.example.test/admin/tools/backups/set-up', ['kind' => 'folder', 'name' => 'This server', 'path' => $this->folder, 'label' => 'Recovery key'])
        ->assertOk();
});
