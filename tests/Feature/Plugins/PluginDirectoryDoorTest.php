<?php

declare(strict_types=1);

use App\Domain\Extensions\Directory\RefreshDirectory;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\PluginWords;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Directory\DirectoryAnswer;
use Teme\Contracts\Directory\VerifiedDirectory;
use Teme\Contracts\Plugins\Capability;

require_once __DIR__.'/../Extensions/Sources/directory-support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $keys = dirKeys();
    config(['teme.directory.public_keys' => [$keys['public']]]);
    $this->files = dirFiles($keys['secret'], [dirPackage('acme/links')]);
    dirServe($this->files);
    $this->door = fn (array $capabilities = ['directory.read']): ?VerifiedDirectory => app(PluginDoors::class)->for('acme/site', 'site', $capabilities, [])[VerifiedDirectory::class] ?? null;
    $this->verify = function (): void {
        app()->instance(Elevation::class, new OperatorElevation);
        app(RefreshDirectory::class)->run();
    };
});

it('is offered only to a plugin granted directory.read, in plain words', function (): void {
    expect(($this->door)([]))->toBeNull()
        ->and(($this->door)())->toBeInstanceOf(VerifiedDirectory::class)
        ->and(Capability::from('directory.read'))->toBe(Capability::DirectoryRead)
        ->and(PluginWords::capability('directory.read'))->toBe('Read the plugins and themes listed in Teme’s directory, as Teme verified them');
});

it('answers the verified index, and a listed package’s verified detail', function (): void {
    ($this->verify)();

    $index = ($this->door)()->index();
    $package = ($this->door)()->package('acme/links');

    expect($index->found())->toBeTrue()
        ->and($index->data['sequence'])->toBe(5)
        ->and($index->data['packages'][0]['name'])->toBe('acme/links')
        ->and($package->data)->toBe(json_decode($this->files['packages/acme/links.json'], true));
});

it('answers the reason when there is nothing verified, and asks the directory nothing itself', function (): void {
    Http::fake();

    expect(($this->door)()->index())->toEqual(new DirectoryAnswer(null, 'Teme hasn’t read the directory’s list yet. It reads it about once an hour.'));
    Http::assertNothingSent();
});

it('answers the reason for a package not listed, and for a detail file that doesn’t match', function (): void {
    ($this->verify)();
    expect(($this->door)()->package('acme/missing')->reason)->toBe('acme/missing isn’t in the directory’s list.');

    $this->files['packages/acme/links.json'] = '{"readme":"changed"}';
    dirServe($this->files);
    expect(($this->door)()->package('acme/links')->reason)->toContain('doesn’t match its signed list');
});

it('answers nothing while the directory is switched off', function (): void {
    ($this->verify)();
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('directory', ['enabled' => false], [], [], 0, (string) $admin->id));

    expect(($this->door)()->index()->reason)->toBe('The plugin and theme directory is switched off on this site.');
});
