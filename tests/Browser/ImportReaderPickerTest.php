<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__).'/Fixtures/plugins/readers/src/Readers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (AccountNotificationsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ned Admin'])->assignRole('administrator');
});

/**
 * Enables the readers fixture, which reads the fake export, agreeing to what it asks.
 */
function pickerReadersEnabled(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('readers');
    $manifest = app(PluginRegistry::class)->find('tests/readers')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/readers', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();

    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(ImportReaders::class);
}

it('offers the reader beside WordPress on the upload, and the reader’s file type once it is chosen', function (): void {
    pickerReadersEnabled();

    cmSignIn($this->admin)
        ->navigate('/admin/tools/import')
        ->press('Upload a file')
        ->assertSee('Read the file with')
        ->press('[aria-label="Read the file with"]')
        ->press('Fake export')
        ->assertSee('The file .json that Fake export reads');
});
