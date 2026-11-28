<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    Queue::fake();
    $this->editor = User::factory()->create()->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    $this->click = fn (User $person, string $action = 'prune-now') => $this->actingAs($person)
        ->from(route('plugins.area.show', ['code' => 'placard']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => $action]));
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('queues an action the area’s pages offer for a holder of the area’s permission', function (): void {
    ($this->click)($this->editor)
        ->assertRedirect(route('plugins.area.show', ['code' => 'placard']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prune now queued.']);

    expect(TrackedJob::query()->sole()->started_by)->toBe($this->editor->id);
});

it('lets the area’s permission answer only when the area passes it on, queuing nothing otherwise', function (): void {
    // The settings page's own route still asks for plugins.manage.
    $this->actingAs($this->editor)->post(route('plugins.actions.run', ['code' => 'placard', 'action' => 'prune-now']))->assertForbidden();

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('placard', 'prune-now', (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('takes no permission from its caller but the area’s own, queuing nothing for another', function (): void {
    // One the editor holds that isn't the area's: a caller can't make it answer.
    $held = $this->editor->getAllPermissions()->pluck('name')->first(fn (string $name): bool => ! str_starts_with($name, 'plugin.'));

    expect($held)->toBeString()
        ->and(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('placard', 'prune-now', (string) $this->editor->id, $held)))
        ->toThrow(AuthorizationException::class)
        ->and(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses, queuing nothing, a person without the area’s permission', function (): void {
    $author = User::factory()->create()->assignRole('author');

    ($this->click)($author)->assertInertiaFlash('toast.type', 'error');

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues the same row action on another row while one waits, and returns the waiting job for a second click on the same row', function (): void {
    $ability = 'plugin.placard.manage';
    $first = app(RunPluginAction::class)->execute(new RunPluginActionInput('placard', 'check-code', (string) $this->editor->id, $ability, '11'));
    $other = app(RunPluginAction::class)->execute(new RunPluginActionInput('placard', 'check-code', (string) $this->editor->id, $ability, '22'));
    $again = app(RunPluginAction::class)->execute(new RunPluginActionInput('placard', 'check-code', (string) $this->editor->id, $ability, '11'));

    expect($other['job'])->not->toBe($first['job'])
        ->and($again['job'])->toBe($first['job'])
        ->and(TrackedJob::query()->count())->toBe(2)
        ->and(TrackedJob::query()->where('id', $first['job'])->value('unique_key'))->toBe(RunPluginAction::uniqueKey('check-code', '11'))
        ->and(TrackedJob::query()->where('id', $other['job'])->value('unique_key'))->toBe(RunPluginAction::uniqueKey('check-code', '22'))
        ->and(TrackedJob::query()->where('id', $first['job'])->value('payload'))->toBe(['action' => 'check-code', 'record' => '11']);

    Queue::assertPushed(RunPluginJob::class, 2);
});
