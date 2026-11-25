<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    PluginFixtures::use('hello', 'widened');
    app()->forgetInstance(Elevation::class);

    // What the dialog shows and the page posts back.
    $this->shown = ['version' => '1.0.0', 'capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']];
    $this->events = fn (string $type): int => DB::table('security_events')->where('type', $type)->count();
});

afterEach(fn () => PluginFixtures::forget());

it('enables a plugin after the consent dialog, asking for the password only when it wasn’t confirmed', function (): void {
    $this->actingAs($this->admin)->from(route('plugins.index'))
        ->post('/admin/plugins/hello/enable', $this->shown)
        ->assertRedirect(route('password.confirm'));

    expect(PluginRecord::query()->count())->toBe(0);

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->post('/admin/plugins/hello/enable', $this->shown)
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.message', 'Hello enabled: it runs in the background; there is nothing to open.');

    $record = PluginRecord::query()->sole();

    expect($record->name)->toBe('tests/hello')
        ->and($record->state)->toBe('enabled')
        ->and($record->consented_by)->toBe($this->admin->id)
        ->and($record->consent)->toEqualCanonicalizing(['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished'], 'routes' => []])
        ->and(($this->events)('plugin.enabled'))->toBe(1);
});

it('answers 423 and writes nothing when the page asks in place and the password wasn’t confirmed lately', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Teme-Ask-In-Place', '1')
        ->post('/admin/plugins/hello/enable', $this->shown)
        ->assertStatus(423);

    expect(PluginRecord::query()->count())->toBe(0);
});

it('refuses to enable when what was shown is no longer what the plugin asks for', function (string $code, array $shown): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->post("/admin/plugins/{$code}/enable", $shown)
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'It asks for something different now. Look again before you enable it.');

    expect(PluginRecord::query()->count())->toBe(0);
})->with([
    'less access than it asks for' => ['widened', ['version' => '1.0.0', 'capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]],
    'another version' => ['hello', ['version' => '0.9.0', 'capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]],
]);

it('refuses a body that leaves out what was shown', function (): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->post('/admin/plugins/hello/enable', ['version' => '1.0.0'])
        ->assertSessionHasErrors(['capabilities', 'hosts', 'hooks']);

    expect(PluginRecord::query()->count())->toBe(0);
});

it('answers 404 for a plugin that is not installed and has no row', function (): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())->post('/admin/plugins/nope/enable', $this->shown)->assertNotFound();
    $this->actingAs($this->admin)->post('/admin/plugins/nope/disable')->assertNotFound();
});

it('disables a plugin with one click, keeping what was agreed', function (): void {
    PluginRecord::factory()->enabled()->create(['name' => 'tests/hello', 'code' => 'hello', 'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]]);

    $this->actingAs($this->admin)->post('/admin/plugins/hello/disable')
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.message', 'Hello disabled. Everything it added to the admin is gone until you enable it; its data is kept.');

    $record = PluginRecord::query()->sole();

    expect($record->state)->toBe('disabled')
        ->and($record->disabled_by)->toBe($this->admin->id)
        ->and($record->consent['capabilities'])->toBe(['content.read'])
        ->and(($this->events)('plugin.disabled'))->toBe(1);
});

it('says so when asked to disable a plugin that isn’t enabled, and writes nothing', function (): void {
    $this->actingAs($this->admin)->post('/admin/plugins/hello/disable')
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'It isn’t enabled.');

    expect(PluginRecord::query()->count())->toBe(0);
});

it('writes nothing for someone without plugins.manage, and shows them no page', function (string $role): void {
    PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened']);
    $person = User::factory()->create()->assignRole($role);
    $before = PluginRecord::query()->orderBy('name')->get()->toArray();

    $this->actingAs($person)->withSession(scConfirmed())->get('/admin/plugins')->assertForbidden();
    $this->actingAs($person)->withSession(scConfirmed())->post('/admin/plugins/hello/enable', $this->shown)->assertForbidden();
    $this->actingAs($person)->post('/admin/plugins/widened/disable')->assertForbidden();

    expect(PluginRecord::query()->orderBy('name')->get()->toArray())->toBe($before)
        ->and(($this->events)('plugin.enabled'))->toBe(0)
        ->and(($this->events)('plugin.disabled'))->toBe(0);
})->with(['editor', 'author', 'contributor']);

it('has the Operations refuse someone without plugins.manage who gets past the route', function (): void {
    $this->withoutMiddleware(Authorize::class);
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->withSession(scConfirmed())->post('/admin/plugins/hello/enable', $this->shown)->assertForbidden();

    expect(PluginRecord::query()->count())->toBe(0);
});

it('draws the page even when an enabled plugin breaks every other admin page', function (): void {
    PluginFixtures::use('broken-start');
    PluginRecord::factory()->enabled()->create(['name' => 'tests/broken-start', 'code' => 'broken-start', 'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]]);

    // As a site answering the Plugins page, not the test suite: active() reads the request.
    app()['env'] = 'production';
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
    app()->instance('request', Request::create('/admin/plugins'));
    app(PluginLoader::class)->load();
    app()['env'] = 'testing';
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), null);

    expect(app(LoadedPlugins::class)->failure('tests/broken-start'))->toBeNull();

    $row = collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->firstWhere('name', 'tests/broken-start');

    expect($row)->toMatchArray(['badge' => 'Enabled', 'action' => 'disable']);
});
