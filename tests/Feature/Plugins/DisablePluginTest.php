<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->consent = ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']];
    $this->record = PluginRecord::factory()->enabled()->create(['name' => 'tests/hello', 'code' => 'hello', 'consent' => $this->consent]);
    $this->events = fn (): int => DB::table('security_events')->where('type', 'plugin.disabled')->count();
});

it('disables a plugin, by the person, and keeps its row and its consent', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $name = app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', (string) $admin->id));
    $record = $this->record->fresh();

    expect($name)->toBe('tests/hello')
        ->and($record?->state)->toBe('disabled')
        ->and($record?->disabled_reason)->toBe('by_user')
        ->and($record?->disabled_by)->toBe($admin->id)
        ->and($record?->disabled_at)->not->toBeNull()
        ->and($record?->consent)->toEqualCanonicalizing($this->consent)
        ->and($record?->version)->toBe($this->record->version)
        ->and(($this->events)())->toBe(1);
});

it('disables a plugin for the operator', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));

    expect($this->record->fresh()?->state)->toBe('disabled')
        ->and($this->record->fresh()?->disabled_by)->toBeNull();
});

it('asks no password to disable a plugin', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', (string) $admin->id));

    expect($this->record->fresh()?->state)->toBe('disabled');
});

it('writes nothing when someone without plugins.manage tries to disable a plugin', function (?string $role): void {
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;

    expect(fn () => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', $actorId)))->toThrow(AuthorizationException::class);
    expect($this->record->fresh()?->state)->toBe('enabled')
        ->and(($this->events)())->toBe(0);
})->with(['editor', 'author', 'nobody at the shell' => null]);

it('refuses to disable a plugin that isn’t enabled', function (string $name): void {
    PluginRecord::factory()->disabled()->create(['name' => 'tests/widened', 'code' => 'widened']);
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(DisablePlugin::class)->execute(new DisablePluginInput($name, (string) $admin->id)))
        ->toThrow(ValidationException::class, 'It isn’t enabled.');
    expect(($this->events)())->toBe(0);
})->with(['disabled' => 'tests/widened', 'never enabled' => 'tests/gone']);

it('checks again under the lock, so a plugin Teme stopped meanwhile stays as Teme left it', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $stopped = false;

    // Teme stops the plugin just after the operation checked it, before it takes the lock.
    DB::listen(function ($query) use (&$stopped): void {
        if (! $stopped && str_contains($query->sql, 'exists') && str_contains($query->sql, '"plugins"')) {
            $stopped = true;
            DB::table('plugins')->where('name', 'tests/hello')->update(['state' => 'disabled', 'disabled_reason' => 'quarantined']);
        }
    });

    expect(fn () => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', (string) $admin->id)))
        ->toThrow(ValidationException::class, 'It isn’t enabled.');
    expect($stopped)->toBeTrue()
        ->and($this->record->fresh()?->disabled_reason)->toBe('quarantined')
        ->and($this->record->fresh()?->disabled_by)->toBeNull()
        ->and(($this->events)())->toBe(0);
});
