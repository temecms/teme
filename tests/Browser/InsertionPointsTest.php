<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    PluginFixtures::use('inserts');
    $manifest = app(PluginRegistry::class)->all()['tests/inserts']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/inserts',
        $manifest->version(),
        $manifest->capabilities(),
        $manifest->hosts(),
        $manifest->hooks(),
        null,
    ));
    app(PluginLoader::class)->load();

    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');

    // The theme's header and footer menus bound, so axe sees no empty navigation landmark.
    $about = addrPublished($this->admin, 'About', 'page');

    foreach (['primary' => 'Primary', 'footer' => 'Footer'] as $slot => $name) {
        $menu = navCreate($this->admin, $name);
        navSave($this->admin, $menu, [['kind' => 'content', 'target_id' => $about->id]]);
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $this->admin->id));
    }

    pageArticleFromDocument($this->author, 'Insertion story', [
        docText('Paragraph one.'),
        docText('Paragraph two.'),
        docText('Paragraph three.'),
    ]);
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('sits the inserted block between the right paragraphs and passes axe', function (int $width): void {
    $page = visit('/insertion-story/')->resize($width, 900);

    $page->assertSee('Paragraph one.')
        ->assertSee('Paragraph two.')
        ->assertSee('Inserted')
        ->assertSee('Paragraph three.')
        ->assertScript(<<<'JS'
            (() => {
                const insertion = document.querySelector('[data-test="insertion"]');
                if (insertion === null) {
                    return false;
                }
                const prose = insertion.closest('article') ?? document.body;
                const text = prose.innerText;
                const two = text.indexOf('Paragraph two.');
                const insert = text.indexOf('Inserted');
                const three = text.indexOf('Paragraph three.');
                return two !== -1 && insert !== -1 && three !== -1 && two < insert && insert < three;
            })()
            JS)
        ->assertNoAccessibilityIssues(3);
})->with([1280, 375]);

it('keeps the ads.txt box editable while the host disallows site code, since ads.txt is text, not code', function (): void {
    config(['teme.disallow_site_code' => true]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/site-code');

    $page->assertSee('The host has switched site code off.')
        ->assertPresent('[data-test="site-code-ads-txt-save"]')
        ->assertScript('document.querySelector(\'[data-test="site-code-ads-txt"]\')?.disabled === false');
});
