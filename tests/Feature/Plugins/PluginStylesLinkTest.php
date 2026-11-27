<?php

/**
 * Tests that `teme:plugins:link` copies an enabled plugin's declared
 * stylesheets into public/plugins inside the teme.plugins layer, records a hash
 * for each, refuses what it must, and removes what a disabled plugin or a newer
 * manifest no longer keeps.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/**
 * The cardbox fixture's own sheet, and the copy the command makes of it.
 */
function cardboxSheet(): string
{
    return base_path('tests/Fixtures/plugins/cardbox/dist/note.css');
}

/**
 * Replaces the fixture's sheet for one test, putting the original back afterwards.
 */
function withCardboxSheet(string $contents, Closure $test): void
{
    $original = (string) file_get_contents(cardboxSheet());

    try {
        file_put_contents(cardboxSheet(), $contents);
        $test();
    } finally {
        // A test may have left a link in the sheet's place: remove the link, never write through it.
        if (is_link(cardboxSheet())) {
            unlink(cardboxSheet());
        }

        file_put_contents(cardboxSheet(), $original);
    }
}

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
    $this->enable = function (): void {
        PluginFixtures::use('cardbox');
        $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
        ($this->restart)();
    };
});

afterEach(function (): void {
    File::deleteDirectory(public_path('plugins'));
    PluginFixtures::forget();
});

it('copies an enabled plugin’s sheet inside the teme.plugins layer, with its hash in the manifest', function (): void {
    ($this->enable)();

    $this->artisan('teme:plugins:link')->assertSuccessful();

    expect(public_path('plugins/cardbox/dist/note.css'))->toBeFile()
        ->and((string) file_get_contents(public_path('plugins/cardbox/dist/note.css')))->toContain('@layer teme.plugins {')
        ->and((string) file_get_contents(public_path('plugins/manifest.json')))->toContain('"dist/note.css"');
});

it('removes a disabled plugin’s copies and its manifest entry', function (): void {
    ($this->enable)();
    $this->artisan('teme:plugins:link')->assertSuccessful();

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', null));
    ($this->restart)();
    $this->artisan('teme:plugins:link')->assertSuccessful();

    expect(public_path('plugins/cardbox'))->not->toBeDirectory()
        ->and((string) file_get_contents(public_path('plugins/manifest.json')))->not->toContain('cardbox');
});

it('removes a copy a newer manifest no longer declares, and one for a plugin the registry no longer has', function (): void {
    ($this->enable)();
    File::ensureDirectoryExists(public_path('plugins/cardbox'));
    file_put_contents(public_path('plugins/cardbox/old.css'), 'a sheet a newer manifest dropped');
    File::ensureDirectoryExists(public_path('plugins/gone'));
    file_put_contents(public_path('plugins/gone/x.css'), 'a plugin that was removed');
    file_put_contents(public_path('plugins/manifest.json'), json_encode(['cardbox' => ['old.css' => 'x'], 'gone' => ['x.css' => 'x']]));

    $this->artisan('teme:plugins:link')->assertSuccessful();

    expect(public_path('plugins/cardbox/old.css'))->not->toBeFile()
        ->and(public_path('plugins/gone/x.css'))->not->toBeFile()
        ->and(public_path('plugins/cardbox/dist/note.css'))->toBeFile();
});

it('puts a leading @charset first and refuses a sheet that imports another', function (): void {
    ($this->enable)();

    withCardboxSheet('@charset "utf-8";@layer teme.plugins{.a{color:red}}', function (): void {
        $this->artisan('teme:plugins:link')->assertSuccessful();

        expect(strpos((string) file_get_contents(public_path('plugins/cardbox/dist/note.css')), '@charset'))->toBe(0);
    });

    withCardboxSheet('@import url("other.css");.a{color:red}', function (): void {
        $this->artisan('teme:plugins:link')->assertSuccessful();

        expect(public_path('plugins/cardbox/dist/note.css'))->not->toBeFile();
    });
});

it('copies nothing from a sheet that is a symbolic link', function (): void {
    ($this->enable)();
    $outside = sys_get_temp_dir().'/teme-link-target-'.uniqid().'.css';
    file_put_contents($outside, '.a{color:red}');

    withCardboxSheet('', function () use ($outside): void {
        unlink(cardboxSheet());
        symlink($outside, cardboxSheet());

        $this->artisan('teme:plugins:link')->assertSuccessful();

        expect(public_path('plugins/cardbox/dist/note.css'))->not->toBeFile();
    });

    @unlink($outside);
});

it('copies nothing from a folder that is a symbolic link', function (): void {
    ($this->enable)();
    $outside = sys_get_temp_dir().'/teme-folder-target-'.uniqid();
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/note.css', '.a{color:red}');
    $folder = base_path('tests/Fixtures/plugins/cardbox/dist');
    $kept = $folder.'.kept';

    try {
        rename($folder, $kept);
        symlink($outside, $folder);

        $this->artisan('teme:plugins:link')->assertSuccessful();

        expect(public_path('plugins/cardbox/dist/note.css'))->not->toBeFile();
    } finally {
        @unlink($folder);
        rename($kept, $folder);
        File::deleteDirectory($outside);
    }
});
