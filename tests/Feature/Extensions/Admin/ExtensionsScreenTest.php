<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Design\Queries\DesignScreen;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Themes\ThemeRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.extensions.path' => null]);
});

afterEach(function (): void {
    $root = config('teme.extensions.path');

    if (is_string($root) && str_contains($root, 'teme-ext-admin-')) {
        File::deleteDirectory($root);
    }
});

it('lists the newest twenty requests, newest first, with who asked', function (): void {
    $admin = extAdmin();

    foreach (range(1, 22) as $minutes) {
        extRequestRow('done', ['requested_at' => now()->subMinutes(100 - $minutes), 'package' => "acme/p{$minutes}", 'requested_by' => $admin->id]);
    }

    $requests = app(ExtensionsScreen::class)->data()['requests'];

    expect($requests)->toHaveCount(20)
        ->and($requests[0]['package'])->toBe('acme/p22')
        ->and($requests[19]['package'])->toBe('acme/p3')
        ->and($requests[0]['requestedBy'])->toBe($admin->name)
        ->and($requests[0]['status'])->toBe('done');
});

it('shows the open request with its steps, what waits for agreement, and what may be done with it', function (): void {
    $steps = [['step' => 'checking', 'state' => 'done', 'startedAt' => '2027-07-20T10:00:00+00:00', 'finishedAt' => '2027-07-20T10:00:01+00:00', 'detail' => null, 'internal' => 'x']];
    extRequestRow('waiting', ['step' => 'validating', 'steps' => json_encode($steps), 'needs' => json_encode(['composerPlugins' => ['acme/plugin']])]);

    $open = app(ExtensionsScreen::class)->data()['open'];

    expect($open['status'])->toBe('waiting')
        ->and($open['steps'])->toBe([['step' => 'checking', 'state' => 'done', 'startedAt' => '2027-07-20T10:00:00+00:00', 'finishedAt' => '2027-07-20T10:00:01+00:00', 'detail' => null]])
        ->and($open['needs'])->toBe(['composerPlugins' => ['acme/plugin']])
        ->and($open['canCancel'])->toBeTrue()
        ->and($open['canAgree'])->toBeTrue();
});

it('has no open request when every request has ended', function (): void {
    extRequestRow('failed');

    expect(app(ExtensionsScreen::class)->data()['open'])->toBeNull();
});

it('says when the installer was last seen, and whether it counts as running', function (): void {
    expect(app(ExtensionsScreen::class)->data())->toMatchArray(['installerSeenAt' => null, 'installerPresent' => false]);

    app(Heartbeats::class)->beat(Heartbeats::INSTALLER);

    expect(app(ExtensionsScreen::class)->data()['installerPresent'])->toBeTrue()
        ->and(app(ExtensionsScreen::class)->data()['installerSeenAt'])->not->toBeNull();

    $this->travel(3)->minutes();

    expect(app(ExtensionsScreen::class)->data()['installerPresent'])->toBeFalse();
});

it('fills the commands for a request with its real values', function (): void {
    $root = extFolder();
    extRequestRow('requested', ['package' => 'acme/link-suggestions', 'constraint' => '^1.2']);

    $commands = app(ExtensionsScreen::class)->data()['open']['commands'];

    expect($commands['docker'])->toBe('docker compose run --rm installer --once')
        ->and($commands['server'])->toContain("TEME_EXTENSIONS_PATH='{$root}' php artisan teme:installer --once")
        ->and($commands['server'])->toStartWith("cd '".base_path()."' && sudo -u ")
        ->and($commands['build'])->toBe("composer require 'acme/link-suggestions:^1.2'");
});

it('offers only the site’s own build on a server with no extensions folder', function (): void {
    extRequestRow('requested', ['action' => 'remove', 'package' => 'acme/hello', 'constraint' => null, 'source_kind' => null]);

    expect(app(ExtensionsScreen::class)->data())
        ->folder->toBeFalse()
        ->open->commands->toBe(['docker' => null, 'server' => null, 'build' => "composer remove 'acme/hello'"]);
});

it('lists the saved sources without their keys, saying only whether one is kept', function (): void {
    DB::table('extension_sources')->insert([
        ['id' => strtolower((string) Str::ulid()), 'kind' => 'composer', 'label' => 'Vendor', 'url' => 'https://repo.example.com', 'username' => 'me', 'credential' => encrypt('the-licence-key', false), 'created_at' => now(), 'updated_at' => now()],
        ['id' => strtolower((string) Str::ulid()), 'kind' => 'git', 'label' => 'Public Git', 'url' => 'https://git.example.com/a.git', 'username' => null, 'credential' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $data = app(ExtensionsScreen::class)->data();

    expect(array_column($data['sources'], 'hasKey', 'label'))->toBe(['Public Git' => false, 'Vendor' => true])
        ->and(json_encode($data))->not->toContain('the-licence-key')
        ->and(json_encode($data))->not->toContain('credential')
        ->and(json_encode($data))->not->toContain(DB::table('extension_sources')->whereNotNull('credential')->value('credential'));
});

it('allows a roll back only when an earlier release is kept', function (): void {
    extFolder(['acme/hello' => ['type' => 'teme-plugin']]);
    expect(app(ExtensionsScreen::class)->data()['canRollBack'])->toBeFalse();

    extFolder(['acme/hello' => ['type' => 'teme-plugin']], earlier: 2);
    expect(app(ExtensionsScreen::class)->data()['canRollBack'])->toBeTrue()
        ->and(app(ExtensionsScreen::class)->earlierReleases())->toHaveCount(2);
});

it('tells each package’s origin by its folder, with the source it came from', function (): void {
    $root = extFolder(['acme/hello' => ['type' => 'teme-plugin', 'source' => 'git']]);
    $release = basename((string) readlink($root.'/current'));

    expect(app(ExtensionsScreen::class)->origins([
        'acme/hello' => "{$root}/releases/{$release}/vendor/acme/hello",
        'teme/seo' => base_path('vendor/teme/seo'),
    ]))->toBe([
        'acme/hello' => ['origin' => 'extensions', 'source' => 'git', 'package' => 'acme/hello'],
        'teme/seo' => ['origin' => 'image', 'source' => null, 'package' => null],
    ]);
});

it('reads the folder’s packages: plugins and themes only, each with its code and whether it was asked for', function (): void {
    extFolder([
        'acme/hello' => ['type' => 'teme-plugin', 'version' => '1.2.0', 'code' => 'hello'],
        'acme/look' => ['type' => 'teme-theme', 'code' => 'look', 'dependency' => true],
        'acme/library' => ['type' => 'library'],
    ]);

    expect(app(ExtensionsScreen::class)->folderPackages())->toBe([
        'acme/hello' => ['name' => 'acme/hello', 'type' => 'teme-plugin', 'version' => '1.2.0', 'code' => 'hello', 'source' => 'packagist', 'requested' => true],
        'acme/look' => ['name' => 'acme/look', 'type' => 'teme-theme', 'version' => '1.0.0', 'code' => 'look', 'source' => null, 'requested' => false],
    ]);
});

it('marks each plugin and theme row with where it comes from', function (): void {
    $root = extFolder(['tests/hello' => ['type' => 'teme-plugin', 'source' => 'git'], 'fixtures/hello-theme' => ['type' => 'teme-theme', 'code' => 'hello-theme']]);
    $release = $root.'/releases/'.basename((string) readlink($root.'/current'));
    File::copyDirectory(PluginFixtures::path('hello'), "{$release}/vendor/tests/hello");
    File::copyDirectory(base_path('tests/Fixtures/Extensions/packages/hello-theme'), "{$release}/vendor/fixtures/hello-theme");
    PluginFixtures::use("{$release}/vendor/tests/hello", 'loud');
    config(['teme.theme_paths' => ["{$release}/vendor/fixtures/hello-theme"]]);
    app(ThemeRegistry::class)->flush();

    $plugins = collect(app(PluginsScreen::class)->rows())->keyBy('name');
    $themes = collect(app(DesignScreen::class)->data()['themes'])->keyBy('code');

    expect($plugins['tests/hello'])->toMatchArray(['origin' => 'extensions', 'source' => 'git'])
        ->and($plugins['tests/loud'])->toMatchArray(['origin' => 'image', 'source' => null])
        ->and($themes['hello-theme'])->toMatchArray(['origin' => 'extensions', 'source' => 'packagist', 'package' => 'fixtures/hello-theme'])
        ->and($themes->except('hello-theme')->pluck('origin')->unique()->all())->toBe(['image']);
});
