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

    app(ToolLinks::class)->add(new ToolLink('acme/example-sync', 'example-sync', 'open', 'Open Sync', 'Your keyword workspace.', 'external-link', 'https://sync.example/projects'));
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
