<?php

declare(strict_types=1);

use App\Domain\Plugins\Console\DeclaredCommands;
use App\Domain\Plugins\Console\PluginCommands;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Sharely\Sharely;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/sharely/src/Sharely.php';

uses(RefreshDatabase::class);

afterEach(fn () => PluginFixtures::forget());

/**
 * Enables Sharely, loads it, and builds the console again, as a process that
 * booted with it enabled does.
 */
function sharelyEnabled(): void
{
    Sharely::reset();
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app()->instance(Elevation::class, new OperatorElevation);

    PluginFixtures::use('sharely');
    $manifest = app(PluginRegistry::class)->find('tests/sharely')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/sharely', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app(Kernel::class)->setArtisan(null);
}

it('lists an enabled plugin’s commands with their descriptions, and runs one with its arguments and options as plain arrays', function (): void {
    sharelyEnabled();

    expect(Artisan::all())->toHaveKeys(['sharely:resend', 'sharely:fail'])
        ->and(Artisan::all()['sharely:resend']->getDescription())->toBe('Resend the shares that failed');

    $exit = Artisan::call('sharely:resend', ['service' => 'mastodon', '--limit' => '5']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Resending <b>shares</b>')->toContain('Resent 5')->toContain('Skipped mastodon')->toContain('Nothing failed really')
        ->and(Sharely::$asked)->toBe([['sharely:resend', ['service' => 'mastodon'], ['limit' => '5', 'dry-run' => false]]])
        ->and(app(Elevation::class)->isOperator())->toBeTrue();
});

it('gives a flag as true, an option or argument not given as null, and the plugin’s exit code back', function (): void {
    sharelyEnabled();

    $exit = Artisan::call('sharely:resend', ['--dry-run' => true]);

    expect($exit)->toBe(2)
        ->and(Sharely::$asked[0][1])->toBe(['service' => null])
        ->and(Sharely::$asked[0][2])->toBe(['limit' => null, 'dry-run' => true]);
});

it('fails a command whose plugin throws, saying the first line, and lets the others run', function (): void {
    sharelyEnabled();

    $exit = Artisan::call('sharely:fail');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('The command sharely:fail failed: It broke')->not->toContain('second line')
        ->and(Artisan::call('sharely:resend'))->toBe(0);
});

it('has no command for a plugin that is installed but not enabled', function (): void {
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PluginFixtures::use('sharely');
    app(PluginLoader::class)->load();
    app(Kernel::class)->setArtisan(null);

    expect(Artisan::all())->not->toHaveKey('sharely:resend');
});

it('has no command once the plugin is disabled', function (): void {
    sharelyEnabled();
    PluginRecord::query()->where('code', 'sharely')->update(['state' => PluginRecord::DISABLED]);
    // A new process: nothing kept from the one that had it enabled.
    Cache::memo()->forget(PluginRecords::CACHE_KEY);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app()->instance(PluginCommands::class, new PluginCommands);
    app(PluginLoader::class)->load();
    app(Kernel::class)->setArtisan(null);

    expect(Artisan::all())->not->toHaveKey('sharely:resend');
});

it('refuses at enable a command that doesn’t start with the plugin’s code and a colon', function (string $name): void {
    Sharely::reset();
    $folder = PluginFixtures::copy('sharely', ['contributes' => ['commands' => [['name' => $name, 'description' => 'Resend']]]]);
    PluginFixtures::use($folder);
    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/sharely', '1.0.0', [], [], [], null)))->toThrow(ValidationException::class)
        ->and(PluginRecord::query()->count())->toBe(0);
})->with([
    'another plugin’s code' => ['other:resend'],
    'no code' => ['resend:now'],
    'Teme’s own space' => ['teme:resend'],
]);

it('says why a declaration can’t be used', function (mixed $commands, string $words): void {
    expect(implode(' | ', DeclaredCommands::errors($commands)))->toContain($words);
})->with([
    'not a list' => [['name' => 'a:b'], 'must be a list'],
    'too many' => [array_map(fn (int $n): array => ['name' => 'sharely:c'.$n, 'description' => 'x'], range(1, 11)), 'at most 10 commands'],
    'a bad name' => [[['name' => 'Resend', 'description' => 'x']], 'A command’s name is'],
    'twice' => [[['name' => 'sharely:a', 'description' => 'x'], ['name' => 'sharely:a', 'description' => 'x']], 'declared more than once'],
    'no description' => [[['name' => 'sharely:a']], 'one-line description'],
    'a taken option' => [[['name' => 'sharely:a', 'description' => 'x', 'options' => [['name' => 'verbose', 'description' => 'x']]]], 'that Teme doesn’t use itself'],
    'an unknown key' => [[['name' => 'sharely:a', 'description' => 'x', 'capability' => 'http']], 'an object of a name'],
]);
