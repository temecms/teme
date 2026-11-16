<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

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
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('columns');
    $manifest = app(PluginRegistry::class)->all()['tests/columns']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/columns',
        $manifest->version(),
        $manifest->capabilities(),
        $manifest->hosts(),
        $manifest->hooks(),
        null,
    ));
    app(PluginLoader::class)->load();

    $this->harbour = lifecycleDraft($this->editor, 'Harbour notes');
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

afterEach(fn () => PluginFixtures::forget());

it('turns on a plugin column in Columns and keeps it after a reload', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/posts');
    $page->click('[data-test="columns"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') !== null");
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]')?.getAnimations().length === 0");
    $page->assertSeeIn('[data-test="column-chooser"]', 'SEO score');
    $page->click('[data-test="column-chooser"] label:has-text("SEO score")');
    $page->click('[data-test="column-chooser"] button:has-text("Apply")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') === null");
    cmWaitUntil($page, "[...document.querySelectorAll('thead th')].some((cell) => cell.textContent.includes('SEO score'))");
    $page->assertSee('Good');

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/posts');
    cmWaitUntil($page, "[...document.querySelectorAll('thead th')].some((cell) => cell.textContent.includes('SEO score'))");
    $page->assertSee('Good')
        ->assertScript(
            "[...document.querySelectorAll('thead th')].find((cell) => cell.textContent.includes('SEO score'))?.querySelector('.sr-only')?.textContent.trim()",
            'From Columns',
        );
    expect(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});

it('shows a plugin\'s markup as text', function (): void {
    lscrPrefer($this->editor, ['lists.post.columns' => ['plugin:columns:note']]);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        'plugin.columns',
        ['mode' => 'markup'],
        [],
        [],
        0,
        (string) $this->admin->id,
    ));

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/posts');
    cmWaitUntil($page, "document.querySelector('table')?.innerText.includes('<img src=x onerror=alert(1)>')");
    $page->assertScript("document.querySelector('table td img') === null", true);
    expect(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});

it('says once that a column couldn\'t be shown, and keeps the rest of the list', function (): void {
    lscrPrefer($this->editor, ['lists.post.columns' => ['plugin:columns:note', 'author']]);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        'plugin.columns',
        ['mode' => 'throw'],
        [],
        [],
        0,
        (string) $this->admin->id,
    ));

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/posts');
    $page->assertSee('Some plugin columns could not be shown this time: Columns. The rest of the list is unaffected.')
        ->assertSee('Harbour notes');
    expect(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});

it('puts a shown plugin column in a card\'s facts on a phone, after the core facts', function (): void {
    lscrPrefer($this->editor, ['lists.post.columns' => ['plugin:columns:score']]);

    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), '/admin/posts');
    cmWaitUntil($page, "document.querySelector('[data-card-facts]') !== null");
    $page->assertAttribute('[data-card-facts]', 'data-card-facts', 'status date author plugin:columns:score');
    expect($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});
