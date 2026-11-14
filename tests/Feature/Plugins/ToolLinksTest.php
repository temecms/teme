<?php

declare(strict_types=1);

use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $links = app(ToolLinks::class);
    $links->add(new ToolLink('acme/example-sync', 'example-sync', 'open', 'Open Sync', 'Your keyword workspace.', 'external-link', 'https://sync.example/projects'));
    $links->add(new ToolLink('acme/example-sync', 'example-sync', 'settings', 'Sync settings', 'Its token and project.', 'settings', 'settings'));
    $links->add(new ToolLink('acme/feeds', 'feeds', 'pings', 'Feed pings', 'Who heard of your posts.', 'send', 'https://hub.example/'));
});

it('lists a plugin’s links on Tools after core’s pages, by label, to plugins.manage holders only', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');

    $keys = array_column(app(ToolsHub::class)->pagesFor($admin), 'key');

    expect(array_slice($keys, 0, 7))->toBe(['jobs', 'import', 'backups', 'updates', 'page-cache', 'redirects', 'ai-activity'])
        ->and(array_slice($keys, 7))->toBe(['plugin.feeds.pings', 'plugin.example-sync.open', 'plugin.example-sync.settings'])
        ->and(array_column(app(ToolsHub::class)->pagesFor($editor), 'key'))->toBe(['redirects']);

    $this->actingAs($admin)->get('/admin/tools')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/index')->has('tools', 10)->where('tools.8.label', 'Open Sync'));
});

it('links to the plugin’s settings page, or to an outside https address marked external', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $pages = collect(app(ToolsHub::class)->pagesFor($admin))->keyBy('key');

    expect($pages['plugin.example-sync.settings'])->toBe([
        'key' => 'plugin.example-sync.settings',
        'label' => 'Sync settings',
        'icon' => 'settings',
        'description' => 'Its token and project.',
        'href' => '/admin/settings/plugin.example-sync',
        'external' => false,
    ])
        ->and($pages['plugin.example-sync.open'])->toBe([
            'key' => 'plugin.example-sync.open',
            'label' => 'Open Sync',
            'icon' => 'external-link',
            'description' => 'Your keyword workspace.',
            'href' => 'https://sync.example/projects',
            'external' => true,
        ])
        ->and($pages['jobs']['external'])->toBeFalse();

    // The palette's Go to opens it in a new tab.
    $screens = collect(app(AdminNavigation::class)->forUser($admin->id))->firstWhere('key', 'tools')['screens'] ?? [];

    expect(collect($screens)->firstWhere('key', 'plugin.example-sync.open'))->toBe(['key' => 'plugin.example-sync.open', 'label' => 'Open Sync', 'icon' => 'external-link', 'href' => 'https://sync.example/projects', 'external' => true])
        ->and(collect($screens)->firstWhere('key', 'jobs'))->not->toHaveKey('external');
});

it('refuses a surface, an icon or an address the first release doesn’t offer', function (array $link, string $words): void {
    $declared = ['surface' => 'tools', 'id' => 'open', 'label' => 'Open Sync', 'to' => 'https://sync.example', ...$link];

    expect(app(ContributionsValidator::class)->errors(['admin' => [$declared]], '1.2.0'))->toBe([$words]);
})->with([
    'a surface' => [['surface' => 'editor.pane'], 'Teme doesn’t offer the editor.pane surface to plugins yet.'],
    'an icon' => [['icon' => 'skull'], 'skull isn’t an icon a plugin can use.'],
    'plain http' => [['to' => 'http://x.example'], 'A link must go to settings or to an https:// address.'],
    'an admin address' => [['to' => '/admin/users'], 'A link must go to settings or to an https:// address.'],
    'settings with a trailing newline' => [['to' => "settings\n"], 'A link must go to settings or to an https:// address.'],
    'an address with a trailing newline' => [['to' => "https://sync.example\n"], 'A link must go to settings or to an https:// address.'],
    'an id with a trailing newline' => [['id' => "open\n"], 'A link’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.'],
]);
