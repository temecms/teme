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
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * The item picker in a record's form (docs/plans/plugin-sockets-2.md, the
 * pickers): a copy of the placard fixture with an items field beside its
 * categories. The search lists only what the editor may open, a chosen item
 * becomes a chip, and a second search marks it Chosen.
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

    // An items field follows the terms field, in the same rules section.
    $withPicks = function (array $node) use (&$withPicks): array {
        $node = array_map(fn (mixed $child): mixed => is_array($child) ? $withPicks($child) : $child, $node);

        if (array_is_list($node)) {
            foreach ($node as $index => $child) {
                if (is_array($child) && ($child['key'] ?? null) === 'terms') {
                    array_splice($node, $index + 1, 0, [['key' => 'picks', 'type' => 'items', 'label' => 'Only on these articles']]);

                    break;
                }
            }
        }

        return $node;
    };
    $manifest = $withPicks(PluginFixtures::manifest('placard'));
    PluginFixtures::use(PluginFixtures::copy('placard', $manifest));
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();

    addrPublished($this->editor, 'Roses in the Sport garden');
    lifecycleDraft($this->editor, 'Roses my draft');

    $this->actingAs($this->editor)->get('/admin/plugin/placard')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('lists the posts and pages that match, chooses one as a chip, and marks it Chosen after', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    $page->click('[data-test="area-table-add"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');

    $page->click('#field-values-picks');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="combobox-search"] input\') !== null');
    $page->type('[data-slot="combobox-search"] input', 'Roses');
    cmWaitUntil($page, 'document.querySelectorAll(\'[role="option"]\').length === 2');

    // The draft is listed with its Draft pill, and nothing is cut off, so there is no foot line.
    $page->assertScript('document.body.innerText.includes("Draft")', true)
        ->assertScript('document.body.innerText.includes("Showing")', false);

    $page->click('[role="option"]:has-text("Roses in the Sport garden")');
    cmWaitUntil($page, 'document.querySelector(\'[aria-label="Remove Roses in the Sport garden"]\') !== null');

    $page->click('#field-values-picks');
    $page->type('[data-slot="combobox-search"] input', 'Roses');
    cmWaitUntil($page, 'document.body.innerText.includes("Chosen")');
    $page->assertScript('document.querySelector(\'[role="option"][aria-disabled="true"]\')?.textContent.includes("Chosen")', true);
    cmNoJavaScriptErrors($page);
});
