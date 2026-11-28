<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
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
use Teme\Contracts\Admin\FieldChoice;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * A select whose options the plugin lists as its form opens: the options
 * show, and when the plugin can't list them the select is disabled with the
 * saved value, the alert says so, and Try again lists them.
 */
beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    $withAccount = function (array $node) use (&$withAccount): array {
        $node = array_map(fn (mixed $child): mixed => is_array($child) ? $withAccount($child) : $child, $node);

        if (array_is_list($node)) {
            foreach ($node as $index => $child) {
                if (is_array($child) && ($child['key'] ?? null) === 'terms') {
                    array_splice($node, $index + 1, 0, [['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin']]);

                    break;
                }
            }
        }

        return $node;
    };
    $manifest = $withAccount(PluginFixtures::manifest('placard'));
    PluginFixtures::use(PluginFixtures::copy('placard', $manifest));
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();

    PlacardArea::$choices = [new FieldChoice('acc-1', 'Main account'), new FieldChoice('acc-2', 'Second account')];

    $this->actingAs($this->editor)->get('/admin/plugin/placard')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('says so when the plugin can’t list its accounts, disables the select, and lists them again on Try again', function (): void {
    // The plugin can't list its accounts when the form opens: the select is disabled with the saved value, and the alert offers Try again.
    PlacardArea::$choicesThrow = true;
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    $page->click('[data-test="area-table-add"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');
    cmWaitUntil($page, 'document.querySelector(\'[role="alert"]\') !== null');
    $page->assertSeeIn('[role="alert"]', 'couldn’t list its ad network account this time');
    $page->assertScript('document.querySelector(\'#field-values-account\')?.disabled === true || document.querySelector(\'#field-values-account\')?.getAttribute("aria-disabled") === "true" || document.querySelector(\'#field-values-account\')?.hasAttribute("data-disabled")', true);

    // Listing works again: Try again asks the page for the form, and the alert goes.
    PlacardArea::$choicesThrow = false;
    $page->click('[role="alert"] >> text="Try again"');
    cmWaitUntil($page, 'document.querySelector(\'[role="alert"]\') === null');

    cmNoJavaScriptErrors($page);
});
