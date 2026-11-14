<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

afterEach(fn () => PluginFixtures::forget());

it('lists plugins with their version, state and why', function (): void {
    PluginFixtures::use('hello', 'widened');
    PluginRecord::factory()->enabled()->create(['name' => 'tests/hello', 'code' => 'hello', 'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]]);
    PluginRecord::factory()->enabled()->create(['name' => 'tests/gone', 'code' => 'gone', 'version' => '2.0.0']);

    $exit = Artisan::call('teme:plugins');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Plugin')->toContain('Version')->toContain('State')->toContain('Why')
        ->and($output)->toMatch('/tests\/hello\s+\|\s+1\.0\.0\s+\|\s+Enabled\s+\|\s+\|/')
        ->and($output)->toMatch('/tests\/widened\s+\|\s+1\.0\.0\s+\|\s+Disabled\s+\|\s+Installed, not enabled yet\.\s+\|/')
        ->and($output)->toMatch('/tests\/gone\s+\|\s+2\.0\.0\s+\|\s+Can’t be used\s+\|\s+Its code is no longer installed\. Its settings are kept\.\s+\|/');
});

it('describes the cache command by what it keeps', function (): void {
    expect(Artisan::all()['teme:plugins:cache']->getDescription())->toBe('Cache what Teme found in the installed plugins');
});

it('says so when no plugin is installed', function (): void {
    PluginFixtures::use();

    $exit = Artisan::call('teme:plugins');

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('No plugins are installed.');
});

it('enables a plugin from the console only with --agree, saying first what it asks to', function (): void {
    PluginFixtures::use('widened');

    $exit = Artisan::call('teme:plugins:enable', ['name' => 'tests/widened']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Widened 1.0.0 asks to:')
        ->toContain('Read your posts and pages, including drafts')
        ->toContain('Connect to services outside this site: api.example.com')
        ->toContain('Run it again with --agree to enable it with these.')
        ->and(PluginRecord::query()->count())->toBe(0);

    $exit = Artisan::call('teme:plugins:enable', ['name' => 'tests/widened', '--agree' => true]);
    $output = Artisan::output();
    $record = PluginRecord::query()->sole();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Widened enabled.')
        ->and($record->state)->toBe('enabled')
        ->and($record->consented_by)->toBeNull()
        ->and($record->consent)->toEqualCanonicalizing(['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com'], 'hooks' => ['ContentPublished']])
        ->and(DB::table('security_events')->where('type', 'plugin.enabled')->whereNull('user_id')->count())->toBe(1);
});

it('refuses from the console a plugin that can’t be used, in its own words', function (): void {
    PluginFixtures::use(PluginFixtures::copy('hello', ['requires' => ['teme' => '^0.6']]));

    $exit = Artisan::call('teme:plugins:enable', ['name' => 'tests/hello', '--agree' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('The plugin requires Teme ^0.6, but this is Teme 0.5.0.')
        ->and(PluginRecord::query()->count())->toBe(0);
});

it('says first why a plugin can’t be used, and stops, even without --agree', function (): void {
    PluginFixtures::use(PluginFixtures::copy('hello', ['requires' => ['teme' => '^0.6']]));

    $exit = Artisan::call('teme:plugins:enable', ['name' => 'tests/hello']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The plugin requires Teme ^0.6, but this is Teme 0.5.0.')
        ->not->toContain('asks to:')
        ->not->toContain('Run it again with --agree to enable it with these.')
        ->and(PluginRecord::query()->count())->toBe(0);
});

it('disables a plugin from the console, and says when it already is', function (): void {
    PluginFixtures::use('hello');
    PluginRecord::factory()->enabled()->create(['name' => 'tests/hello', 'code' => 'hello']);

    $exit = Artisan::call('teme:plugins:disable', ['name' => 'tests/hello']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Hello disabled.')
        ->and(PluginRecord::query()->sole()->state)->toBe('disabled')
        ->and(PluginRecord::query()->sole()->disabled_by)->toBeNull();

    $exit = Artisan::call('teme:plugins:disable', ['name' => 'tests/hello']);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('Hello is already disabled.');
});

it('says when no plugin has that name', function (string $command): void {
    PluginFixtures::use('hello');

    $exit = Artisan::call($command, ['name' => 'tests/nobody']);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('No plugin is named "tests/nobody".');
})->with(['teme:plugins:enable', 'teme:plugins:disable']);
