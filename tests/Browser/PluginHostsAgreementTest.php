<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * Agreeing on a plugin's settings page to a host typed into a setting it
 * takes hosts from (docs/plans/plugin-sockets-1.md, A2): the line and the
 * box appear only for a host not agreed yet, a save without the box is
 * refused, and a save with it asks for the password, then keeps the host.
 */
beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('hub');
    $manifest = PluginFixtures::manifest('hub');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/hub', $manifest['version'], $manifest['capabilities'], $manifest['hosts'], [], null, settingHosts: ['hub.example.com']));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
});

afterEach(fn () => PluginFixtures::forget());

it('asks before a plugin reaches a host typed into its settings, refuses a save without the box, and keeps the host after the password', function (int $width): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/settings/plugin.hub');
    cmWaitUntil($page, 'document.querySelector("#field-more_hubs") !== null');
    $page->assertScript('document.querySelector(\'[data-test="hosts-agreement"]\') === null', true);

    $page->type('#field-more_hubs', 'https://hub.example.net/');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="hosts-agreement"]\') !== null');
    $page->assertSeeIn('[data-test="hosts-agreement"]', 'Saving lets Hub connect to hub.example.net.');

    $page->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.body.textContent.includes("Agree to let Hub connect to hub.example.net, or remove them.")');
    expect(PluginRecord::query()->sole()->consent['settingHosts'])->toBe(['hub.example.com']);

    $page->click('#agree-hosts-more_hubs')->click('[data-test="settings-save"]');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.body.textContent.includes("Settings saved.") && document.querySelector(\'[data-slot="dialog-content"]\') === null');

    expect(PluginRecord::query()->sole()->consent['settingHosts'])->toBe(['hub.example.com', 'hub.example.net']);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="hosts-agreement"]\') === null');
    expect($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['wide' => 1280, 'phone' => 375]);

it('passes axe with the agreement showing, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/plugin.hub');
    cmWaitUntil($page, 'document.querySelector("#field-more_hubs") !== null');
    $page->type('#field-more_hubs', 'https://hub.example.net/');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="hosts-agreement"]\') !== null');

    expect(cmAxeViolations($page))->toBe([]);
})->with(['light', 'dark']);
