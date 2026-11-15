<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // The catalogue's plugin area, and what its body says it shows.
    $this->area = '#plugin-area';
    $this->body = '[data-test="plugin-area-body"]';
    $this->ready = 'document.querySelector(\'#plugin-area [data-slot="page-header"]\') !== null';

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/_catalogue')->assertOk();
});

it('heads the area with the plugin’s icon at 64 pixels, its title and its mark', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#plugin-area');
    cmWaitUntil($page, $this->ready);

    $head = $page->script(<<<'JS'
        () => {
            const area = document.querySelector('#plugin-area');
            const icons = area.querySelectorAll('[data-slot="plugin-icon"]');
            const box = icons[0]?.getBoundingClientRect();
            const title = area.querySelector('[data-test="pattern-title"]');

            return {
                icons: icons.length,
                size: box ? [Math.round(box.width), Math.round(box.height)] : null,
                initials: icons[0]?.textContent ?? null,
                title: title?.textContent ?? null,
                markAfter: title?.nextElementSibling?.getAttribute('data-slot') ?? null,
            };
        }
        JS);

    expect($head)->toBe([
        'icons' => 1,
        'size' => [64, 64],
        'initials' => 'LC',
        'title' => 'Link checker',
        'markAfter' => 'plugin-mark',
    ]);
});

it('shows five pages, the rest under More, and marks the current one', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#plugin-area');
    cmWaitUntil($page, $this->ready);

    $nav = 'nav[aria-label="Link checker pages"]';

    $page->assertScript("document.querySelectorAll('{$nav} a').length === 5")
        ->assertScript("document.querySelector('{$nav} button')?.textContent === 'More'")
        ->assertScript("document.querySelector('{$nav} a[aria-current=\"page\"]')?.textContent === 'Overview'");

    $page->click("{$nav} button");
    shellSettled($page, '[role="menu"]');
    $page->assertScript("[...document.querySelectorAll('[role=\"menu\"] [role=\"menuitem\"]')].map((item) => item.textContent).join('|') === 'Exports|Logs'");

    $page->click('[role="menu"] a[href*="area=logs"]');
    cmWaitUntil($page, "document.querySelector('{$nav} button[aria-current=\"page\"]')?.textContent === 'Logs'");
    cmWaitUntil($page, "document.querySelector('{$this->body}')?.textContent.includes('Logs') === true");
});

it('shows a failed page as one sentence naming the plugin, and Try again brings it back', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#plugin-area');
    cmWaitUntil($page, $this->ready);

    $page->click('[data-test="plugin-area-break"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="plugin-area-failed"] [role="alert"]\') !== null');

    $page->assertScript('document.querySelector(\'[data-test="plugin-area-failed"] [role="alert"] p\').textContent === "Couldn’t show this page of Link checker. The rest of the admin is unaffected."')
        // The rest of the catalogue is still drawn.
        ->assertScript('document.querySelector(\'#plugin-icon\') !== null && document.querySelector(\'#patterns\') !== null')
        ->assertScript("document.querySelector('{$this->body}') === null");

    $page->click('[data-test="plugin-area-failed"] button');
    cmWaitUntil($page, "document.querySelector('{$this->body}') !== null && document.querySelector('[data-test=\"plugin-area-failed\"]') === null");
});

it('clears a failure when another page of the area opens', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#plugin-area');
    cmWaitUntil($page, $this->ready);

    $page->click('[data-test="plugin-area-break"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="plugin-area-failed"]\') !== null');

    $page->click('nav[aria-label="Link checker pages"] a[href*="area=link-report"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-area-failed\"]') === null && document.querySelector('{$this->body}')?.textContent.includes('Link report') === true");
});

it('passes axe in both schemes at 1280 and 375, every target 24 pixels or more', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/_catalogue#plugin-area');
    cmWaitUntil($page, $this->ready);

    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([])
        ->and((int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0);
})->with(['light', 'dark'])->with([1280, 375]);
