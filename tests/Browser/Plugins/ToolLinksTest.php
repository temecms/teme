<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    app(ToolLinks::class)->add(new ToolLink('acme/example-sync', 'example-sync', 'open', 'Open Sync', 'Your keyword workspace.', 'external-link', 'https://sync.example/projects', plugin: 'Example Sync'));
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.index'))->assertOk();
});

it('opens an outside link in a new tab and tells screen readers so', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/tools');

    cmWaitUntil($page, "document.querySelector('[data-test=\"tool-plugin.example-sync.open\"]') !== null");

    $link = $page->script(<<<'JS'
        () => {
            const link = document.querySelector('[data-test="tool-plugin.example-sync.open"]');

            return {
                href: link.getAttribute('href'),
                target: link.getAttribute('target'),
                rel: link.getAttribute('rel'),
                hidden: Array.from(link.querySelectorAll('.sr-only')).map((span) => span.textContent),
            };
        }
        JS);

    expect($link)->toBe([
        'href' => 'https://sync.example/projects',
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
        'hidden' => ['(opens in a new tab)'],
    ])
        ->and(cmAxeViolations($page))->toBe([]);
});

it('lists plugins’ links under From plugins, each with its plugin’s mark', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/tools');

    cmWaitUntil($page, "document.querySelector('[data-test=\"tool-plugin.example-sync.open\"]') !== null");

    $hub = $page->script(<<<'JS'
        () => {
            const section = document.querySelector('section[aria-labelledby="hub-from-plugins"]');
            const card = document.querySelector('[data-test="tool-plugin.example-sync.open"]');

            return {
                inSection: section?.contains(card) ?? false,
                mark: card?.querySelector('[data-slot="plugin-mark"]')?.textContent ?? null,
                coreInSection: [...(section?.querySelectorAll('[data-test^="tool-"]') ?? [])].filter((link) => !link.getAttribute('data-test').startsWith('tool-plugin.')).length,
            };
        }
        JS);

    expect($hub)->toBe(['inSection' => true, 'mark' => 'Example Sync', 'coreInSection' => 0]);
});

it('marks a plugin’s page in the menu’s Tools entry, named for screen readers', function (): void {
    app(ToolLinks::class)->add(new ToolLink('acme/example-sync', 'example-sync', 'settings', 'Sync settings', 'Its token and project.', 'settings', 'settings', plugin: 'Example Sync'));
    // The menu opens a hub's screens on one of its pages: Tools › Jobs.
    $this->actingAs($this->admin)->get(route('tools.jobs.index'))->assertOk();

    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/tools/jobs');

    cmWaitUntil($page, "document.querySelector('[data-sidebar=\"menu-sub\"] a[href=\"/admin/settings/plugin.example-sync\"]') !== null");

    $entry = $page->script(<<<'JS'
        () => {
            const link = document.querySelector('[data-sidebar="menu-sub"] a[href="/admin/settings/plugin.example-sync"]');
            const plug = link.querySelector('svg');

            return {
                hidden: [...link.querySelectorAll('.sr-only')].map((span) => span.textContent),
                plug: plug?.getAttribute('aria-hidden') ?? null,
                plugAfterLabel: plug !== null && link.firstChild?.nodeType === Node.TEXT_NODE,
                stops: link.querySelectorAll('a, button, [tabindex]').length,
            };
        }
        JS);

    expect($entry)->toBe([
        'hidden' => ['From Example Sync'],
        'plug' => 'true',
        'plugAfterLabel' => true,
        'stops' => 0,
    ]);
});
