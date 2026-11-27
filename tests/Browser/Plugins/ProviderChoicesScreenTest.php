<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PluginFixtures::use('slotted', 'searcher');

    foreach (['tests/slotted', 'tests/searcher'] as $name) {
        $manifest = app(PluginRegistry::class)->find($name)?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput($name, '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    }

    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/plugins/slots')->assertOk();
});

afterEach(fn () => PluginFixtures::forget());

it('clears a choice back to Teme’s own, and draws with no accessibility issue', function (): void {
    DB::table('plugin_slot_choices')->insert(['slot' => PluginSlots::IMAGE_SERVICE, 'plugin' => 'tests/slotted', 'chosen_by' => $this->admin->id, 'chosen_at' => now()]);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/plugins/slots');
    ($this->seen)($page, 'Provider choices');

    expect(cmAxeViolations($page))->toBe([]);

    $page->click('[data-test="slot-'.PluginSlots::IMAGE_SERVICE.'"] [role="combobox"]')
        ->click('[role="option"]:has-text("Teme’s own")');
    ($this->seen)($page, 'Saved.');

    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->exists())->toBeFalse();
    $page->screenshot(true, 'provider-choices-1280');
});

it('shows the notice left when a chosen plugin is turned off', function (): void {
    DB::table('plugin_slot_choices')->insert(['slot' => PluginSlots::IMAGE_SERVICE, 'plugin' => 'tests/slotted', 'chosen_by' => $this->admin->id, 'chosen_at' => now()]);
    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/slotted', null));
    app()->forgetInstance(Elevation::class);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/plugins/slots');
    ($this->seen)($page, 'The Slotted plugin was turned off, so its choice was cleared.');

    expect(cmAxeViolations($page))->toBe([]);
});

it('queues the whole search index again from the Send every item again button', function (): void {
    DB::table('plugin_slot_choices')->insert(['slot' => PluginSlots::SEARCH, 'plugin' => 'tests/searcher', 'chosen_by' => $this->admin->id, 'chosen_at' => now()]);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/plugins/slots');
    ($this->seen)($page, 'Provider choices');

    $page->click('[data-test="slot-'.PluginSlots::SEARCH.'"] button:has-text("Send every item again")');
    ($this->seen)($page, 'The search engine is being sent every published item.');
});
