<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Plugins\Fielded\Fielded;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__).'/Fixtures/plugins/fielded/src/Fielded.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
    Fielded::reset();
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('fills the time field when a reader touches the form, so a cached page still gives the check a fresh time', function (): void {
    $folder = cacheOn();

    // The fielded plugin, enabled with a time field and the comment-check slot.
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PluginFixtures::use(PluginFixtures::copy('fielded', ['contributes' => ['provides' => ['comment-check'], 'commentField' => ['name' => 'clock-stamp', 'make' => 'time']]]));
    $manifest = app(PluginRegistry::class)->find('tests/fielded')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/fielded', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);

    $url = dtUrl($this->site, 'longRead');

    foreach ([1, 2] as $visit) {
        visit($url)
            ->assertScript("document.querySelector('[data-teme-time]').value === ''")
            ->type('#c-text', "Reader words {$visit}.")
            ->type('#c-name', 'Ada Reader')
            ->type('#c-email', 'ada.reader@example.com')
            ->pressAndWaitFor('Post comment')
            ->assertSee('awaiting moderation');
    }

    $stamps = array_column(Fielded::$signals, 'clock-stamp');

    expect($stamps)->toHaveCount(2)
        ->and(ctype_digit($stamps[0]))->toBeTrue()
        ->and(ctype_digit($stamps[1]))->toBeTrue()
        ->and($stamps[1])->not->toBe($stamps[0]);

    cacheOff($folder);
});
