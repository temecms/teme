<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Workbench\Workbench;

uses(RefreshDatabase::class);

// The fixture's classes, loaded before a test resets them.
foreach (glob(dirname(__DIR__).'/Fixtures/plugins/workbench/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the editor in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->harbour = typesDraft($this->editor, 'post', 'Harbour news', []);
    $this->market = typesDraft($this->editor, 'post', 'Market day', []);

    // The workbench plugin, enabled with the jobs it declares and its list action.
    $previous = app(Elevation::class);
    app()->instance(Elevation::class, new OperatorElevation);
    Workbench::reset();
    PluginFixtures::use('workbench');
    $manifest = app(PluginRegistry::class)->find('tests/workbench')?->manifest ?? throw new RuntimeException('The workbench fixture is not found.');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/workbench', $manifest->version(), $manifest->capabilities(), [], [], null));
    app()->instance(Elevation::class, $previous);

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('queues a bulk list action only once the person confirms its sentence', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Select Harbour news"]')
        ->click('[aria-label="Select Market day"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Ping these"')
        ->assertSee('Ping the selected items now?')
        ->click('[role="dialog"] button >> text="Ping these"')
        ->assertNotPresent('[role="dialog"]')
        ->waitForText('Ping these queued');

    expect(TrackedJob::query()->count())->toBe(1);
});
