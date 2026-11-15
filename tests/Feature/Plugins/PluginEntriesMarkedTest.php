<?php

declare(strict_types=1);

use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    app()->instance(StatusChecks::class, new StatusChecks);

    // Example sync's settings and its two Tools links, registered as a starting plugin's are.
    app(Contributions::class)->add('acme/example-sync', 'example-sync', 'Example Sync', '1.2.0', [
        'settings' => [
            ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'Lets Teme tell your workspace.'],
        ],
        'admin' => [
            ['surface' => 'tools', 'id' => 'open', 'label' => 'Open Sync', 'description' => 'Your keyword workspace.', 'icon' => 'external-link', 'to' => 'https://sync.example/projects'],
            ['surface' => 'tools', 'id' => 'settings', 'label' => 'Sync settings', 'to' => 'settings'],
        ],
    ], new stdClass);
});

/**
 * The shared `nav` entry with this key, as the dashboard gives it to this person.
 *
 * @return array<string, mixed>
 */
function pemEntry(mixed $test, User $person, string $key): array
{
    $nav = $test->actingAs($person)->get(route('dashboard'))->viewData('page')['props']['nav'];

    return collect($nav)->firstWhere('key', $key) ?? [];
}

it('names the plugin on each of its Tools links, and on none of Teme’s', function (): void {
    app(ToolLinks::class)->add(new ToolLink('acme/feeds', 'feeds', 'pings', 'Feed pings', 'Who heard of your posts.', 'send', 'https://hub.example/', plugin: 'Feeds'));

    $pages = collect(app(ToolsHub::class)->pagesFor($this->admin))->keyBy('key');

    expect($pages['plugin.feeds.pings']['plugin'] ?? null)->toBe('Feeds')
        ->and($pages['plugin.example-sync.open']['plugin'] ?? null)->toBe('Example Sync')
        ->and($pages['plugin.example-sync.settings']['plugin'] ?? null)->toBe('Example Sync')
        ->and($pages->reject(fn (array $page, string $key): bool => str_starts_with($key, 'plugin.'))->filter(fn (array $page): bool => array_key_exists('plugin', $page))->keys()->all())->toBe([]);
});

it('names the plugin on its settings page in the Settings hub', function (): void {
    $screens = collect(pemEntry($this, $this->admin, 'settings')['screens'] ?? [])->keyBy('key');

    expect($screens['plugin.example-sync']['plugin'] ?? null)->toBe('Example Sync')
        ->and($screens['general'])->not->toHaveKey('plugin');
});

it('carries the plugin’s name into the menu’s Tools screens', function (): void {
    $screens = collect(pemEntry($this, $this->admin, 'tools')['screens'] ?? [])->keyBy('key');

    expect($screens['plugin.example-sync.settings']['plugin'] ?? null)->toBe('Example Sync')
        ->and($screens['plugin.example-sync.open']['plugin'] ?? null)->toBe('Example Sync')
        ->and($screens['jobs'])->not->toHaveKey('plugin');
});

it('shows a plugin’s entries to no one without plugins.manage', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $screens = collect(pemEntry($this, $editor, 'tools')['screens'] ?? [])
        ->merge(pemEntry($this, $editor, 'settings')['screens'] ?? []);

    expect($screens->filter(fn (array $screen): bool => array_key_exists('plugin', $screen))->all())->toBe([])
        ->and(collect(app(ToolsHub::class)->pagesFor($editor))->filter(fn (array $page): bool => array_key_exists('plugin', $page))->all())->toBe([]);
});
