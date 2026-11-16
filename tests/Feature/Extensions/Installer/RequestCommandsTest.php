<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.extensions.path' => null, 'teme.extensions.packagist' => true]);
});

it('asks as the operator with teme:extensions:request, and prints what it recorded', function (): void {
    $this->artisan('teme:extensions:request', ['action' => 'install', 'package' => 'acme/hello', '--constraint' => '^1.0'])
        ->expectsOutput('Install of acme/hello requested. The installer carries it out within a minute.')
        ->assertExitCode(0);

    $row = DB::table('extension_requests')->first();

    expect($row)->toMatchObject(['action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello', 'constraint' => '^1.0', 'source_kind' => 'packagist', 'status' => 'requested'])
        ->and($row->requested_by)->toBeNull();
});

it('asks for a theme with --kind=theme', function (): void {
    $this->artisan('teme:extensions:request', ['action' => 'install', 'package' => 'acme/quiet', '--kind' => 'theme'])->assertExitCode(0);

    expect(DB::table('extension_requests')->value('kind'))->toBe('theme');
});

it('refuses with teme:extensions:request in the refusal’s own words, recording nothing', function (array $arguments, string $words): void {
    $this->artisan('teme:extensions:request', $arguments)->expectsOutput($words)->assertExitCode(1);

    expect(DB::table('extension_requests')->count())->toBe(0);
})->with([
    'a package of the image' => [['action' => 'install', 'package' => 'laravel/framework'], 'laravel/framework is part of Teme’s build, so it can’t be installed again.'],
    'a change it can’t ask for' => [['action' => 'rebuild'], '“rebuild” isn’t a change to ask for: install, update, remove or rollback.'],
    'no package' => [['action' => 'install'], 'Name the package, such as acme/link-suggestions.'],
    'a kind that isn’t one' => [['action' => 'install', 'package' => 'acme/hello', '--kind' => 'widget'], '“widget” isn’t plugin or theme.'],
]);

it('cancels the request that waits with teme:extensions:cancel, as the operator', function (string $status): void {
    $id = strtolower((string) Str::ulid());
    DB::table('extension_requests')->insert(['id' => $id, 'action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello', 'source_kind' => 'packagist', 'status' => $status, 'requested_at' => now()]);

    $this->artisan('teme:extensions:cancel')->expectsOutput('The request to install acme/hello was cancelled.')->assertExitCode(0);

    expect(DB::table('extension_requests')->where('id', $id)->first())->toMatchObject(['status' => 'cancelled', 'cancelled_by' => null]);
})->with(['requested', 'waiting']);

it('says no request waits, when none is open or the installer is carrying it out', function (): void {
    $this->artisan('teme:extensions:cancel')->expectsOutput('No plugin or theme request is waiting.')->assertExitCode(1);

    DB::table('extension_requests')->insert(['id' => strtolower((string) Str::ulid()), 'action' => 'install', 'kind' => 'plugin', 'package' => 'acme/hello', 'source_kind' => 'packagist', 'status' => 'running', 'requested_at' => now()]);

    $this->artisan('teme:extensions:cancel')
        ->expectsOutput('The installer is carrying out this request, so it can’t be cancelled now. It ends on its own.')
        ->assertExitCode(1);

    expect(DB::table('extension_requests')->value('status'))->toBe('running');
});
