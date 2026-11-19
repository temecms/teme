<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * The script that is true when the screen's one h1, in the page's main
 * area, reads exactly $text: assertSeeIn() looks inside an element, never
 * at the element itself.
 */
function menuHeadingIs(string $text): string
{
    return 'document.querySelector("main h1")?.textContent === '.json_encode($text);
}

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

    // Warm the screens before the browser's first visit.
    foreach (['/admin', route('terms.index', ['taxonomy' => 'tags'], false), route('settings.show', ['group' => 'general'], false), route('tools.redirects.index', [], false), route('users.index', [], false)] as $path) {
        $this->actingAs($this->admin)->get($path)->assertOk();
    }
});

it('draws Categories and Tags under Posts only inside Posts, marking the one you are on', function (): void {
    $posts = route('content.index', ['type' => 'posts'], false);
    $tags = route('terms.index', ['taxonomy' => 'tags'], false);
    $top = "a[data-slot=\"sidebar-menu-button\"][href=\"{$posts}\"]";
    $child = "[data-sidebar=\"menu-sub\"] a[href=\"{$tags}\"]";
    // Counted once, never retried: a wrong count fails instead of waiting for ever.
    $drawn = fn (mixed $page): int => (int) $page->script("() => document.querySelectorAll('{$child}').length");

    $page = cmSignIn($this->admin)->assertAttribute($top, 'data-active', 'false');
    expect($drawn($page))->toBe(0);

    $page->navigate($posts)->assertAttribute($top, 'data-active', 'true');
    expect($drawn($page))->toBe(1);

    $page->navigate($tags)
        ->assertAttribute($child, 'data-active', 'true')
        ->assertAttribute($top, 'data-active', 'false');
});

it('marks Settings and Tools current on each of their pages', function (): void {
    // The menu's Settings opens the Settings hub (the approved v10 design).
    $settings = route('settings.index', [], false);
    $tools = route('tools.index', [], false);

    $page = cmSignIn($this->admin)->navigate(route('settings.show', ['group' => 'media'], false))
        ->assertAttribute("a[data-slot=\"sidebar-menu-button\"][href=\"{$settings}\"]", 'data-active', 'true');

    $page->navigate(route('tools.redirects.index', [], false))
        ->assertAttribute("a[data-slot=\"sidebar-menu-button\"][href=\"{$tools}\"]", 'data-active', 'true');
});

it('names People in the menu and on its screen, the menu passing axe and the target size with Posts open, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate(route('users.index', [], false))
        ->assertSeeIn('nav[aria-label="Main"]', 'People')
        ->assertDontSeeIn('nav[aria-label="Main"]', 'Users')
        ->assertScript(menuHeadingIs('People'), true)
        ->assertScript('document.title.startsWith("People")', true);

    // The menu alone: the list and terms screens meet axe in their own parts.
    $page->navigate(route('terms.index', ['taxonomy' => 'tags'], false));
    shellSettled($page, '[data-sidebar="menu-sub"]');

    $found = $page->script(<<<'JS'
        async () => {
            const menu = document.querySelector('nav[aria-label="Main"]');
            const all = await window.axe.run(menu);
            const size = await window.axe.run(menu, { runOnly: { type: 'rule', values: ['target-size'] }, rules: { 'target-size': { enabled: true } } });

            return {
                violations: all.violations.map((violation) => violation.id),
                small: size.violations.flatMap((violation) => violation.nodes.map((node) => node.target.join(' '))),
                checked: size.passes.reduce((sum, rule) => sum + rule.nodes.length, 0),
            };
        }
        JS);

    expect($found['violations'] ?? ['axe did not answer'])->toBe([])
        ->and($found['small'] ?? ['axe did not answer'])->toBe([])
        ->and($found['checked'] ?? 0)->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('draws the Settings menu beside each Settings screen, the current one marked, passing axe and the target size, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $media = route('settings.show', ['group' => 'media'], false);

    $page = cmSignIn($this->admin)->navigate($media)
        ->assertScript(menuHeadingIs('Media'), true)
        ->assertSeeIn('nav[aria-label="Settings"]', 'Search and social')
        ->assertDontSeeIn('nav[aria-label="Settings"]', 'From plugins')
        ->assertAttribute('nav[aria-label="Settings"] a[aria-current="page"]', 'href', $media)
        // The current page opens to its sections, under it.
        ->assertSeeIn('[data-test="settings-sections"]', 'Uploads')
        ->assertSeeIn('[data-test="settings-sections"]', 'Image widths')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('finds a setting as you type, in a list under the box, and takes you to its field', function (): void {
    $page = cmSignIn($this->admin)->navigate(route('settings.show', ['group' => 'media'], false))
        ->type('[data-test="find-setting"]', 'time zone')
        ->assertSeeIn('[data-test="find-setting-list"]', 'Time zone');

    // The list opens under the box; the menu stays.
    expect($page->script('() => document.querySelector(\'nav[aria-label="Settings"]\').closest("[hidden]") === null'))->toBeTrue()
        ->and($page->script('() => document.querySelector(\'[data-test="find-setting-list"] mark\')?.textContent.toLowerCase()'))->toBe('time');

    $page->click('[data-test="find-setting-item"][data-key="general.timezone"]')
        ->assertPathIs(route('settings.show', ['group' => 'general'], false))
        ->assertScript(menuHeadingIs('General'), true)
        ->assertScript('document.activeElement !== null && document.activeElement.id === "field-timezone"', true)
        ->assertValue('[data-test="find-setting"]', '');
});

it('keeps what you typed on the screen while you search, and closes the list on Escape', function (): void {
    $page = cmSignIn($this->admin)->navigate(route('settings.show', ['group' => 'general'], false))
        ->type('#field-site_title', 'The Garden City Ledger')
        ->type('[data-test="find-setting"]', 'tagline')
        ->assertSeeIn('[data-test="find-setting-list"]', 'Tagline')
        ->keys('[data-test="find-setting"]', 'Escape')
        ->assertScript('document.querySelector(\'[data-test="find-setting-list"]\').hidden', true)
        ->assertValue('#field-site_title', 'The Garden City Ledger');

    expect($page->script('() => document.querySelector(\'nav[aria-label="Settings"]\').closest("[hidden]") === null'))->toBeTrue();
});

it('at a phone\'s width, opens the Settings menu from one button, never scrolling the page sideways, passing axe', function (): void {
    $page = cmSignIn($this->admin)->resize(375, 812)
        ->navigate(route('settings.show', ['group' => 'general'], false))
        ->assertScript(menuHeadingIs('General'), true);

    $page->assertScript('getComputedStyle(document.querySelector(\'nav[aria-label="Settings"]\')).display === "none"')
        ->assertScript('(() => { const button = document.querySelector(\'[data-test="settings-groups-button"]\'); return button.textContent.includes("Settings groups") && button.textContent.includes("General") && button.getAttribute("aria-expanded") === "false"; })()');

    $page->click('[data-test="settings-groups-button"]');

    $sizes = $page->script(<<<'JS'
        () => {
            const menu = document.querySelector('nav[aria-label="Settings"]');
            // The pages, not the current page's section links, which sit indented under it.
            const [first, second] = [...menu.querySelectorAll('a[data-test="settings-group"]')].map((link) => link.getBoundingClientRect());

            return {
                expanded: document.querySelector('[data-test="settings-groups-button"]').getAttribute('aria-expanded'),
                shown: getComputedStyle(menu).display !== 'none',
                stacked: Math.round(first.left) === Math.round(second.left) && second.top >= first.bottom,
                // How far the page scrolls sideways: none. The kept scrollbar gutter (I-3130) makes the raw difference negative.
                page: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
            };
        }
        JS);

    expect($sizes)->toBe(['expanded' => 'true', 'shown' => true, 'stacked' => true, 'page' => 0]);

    $page->assertNoAccessibilityIssues(3);
});

it('offers every Settings and Tools page under Go to, each with its icon, matching every word typed', function (): void {
    $page = cmSignIn($this->admin)->click('[data-test="palette-button"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $offered = fn (string $label): string => "Array.from(document.querySelectorAll('[role=\"group\"][aria-label=\"Go to\"] [role=\"option\"]')).some((option) => option.textContent.includes('{$label}') && option.querySelector('svg[aria-hidden=\"true\"]') !== null)";

    $page->type('[role="combobox"]', 'settings media')
        ->assertScript($offered('Settings › Media'), true)
        ->type('[role="combobox"]', 'redirects')
        ->assertScript($offered('Tools › Redirects'), true)
        ->click('Tools › Redirects')
        ->assertPathIs(route('tools.redirects.index', [], false));
});

it('lists what you visited last under Recent, newest first, never the page you are on', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate(route('settings.show', ['group' => 'media'], false))->assertScript(menuHeadingIs('Media'), true)
        ->navigate(route('tools.redirects.index', [], false))->assertScript(menuHeadingIs('Redirects'), true)
        ->navigate(route('settings.show', ['group' => 'general'], false))->assertScript(menuHeadingIs('General'), true);

    $page->click('[data-test="palette-button"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $recent = $page->script(<<<'JS'
        () => Array.from(document.querySelectorAll('[role="group"][aria-label="Recent"] [role="option"] [data-test="palette-label"]')).map((label) => label.textContent)
        JS);

    expect($recent)->toBe(['Tools › Redirects', 'Settings › Media']);
});

it('keeps each person\'s Recent apart in a shared browser', function (): void {
    $editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    // Another person's Recent in this browser, naming a screen the administrator may open too.
    $page = cmSignIn($this->admin);
    $page->script("() => localStorage.setItem('teme.palette.recent.{$editor->id}', JSON.stringify(['/admin/tools/redirects']))");
    $page->click('[data-test="palette-button"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    // Go to offers that screen to the administrator, so only the key keeps it out of their Recent.
    expect($page->script('() => document.querySelector(\'[role="group"][aria-label="Recent"]\') === null'))->toBeTrue()
        ->and($page->script('() => Array.from(document.querySelectorAll(\'[role="group"][aria-label="Go to"] [data-test="palette-label"]\')).some((label) => label.textContent === "Tools › Redirects")'))->toBeTrue();
});

it('finds a setting by its name and opens it at its field', function (): void {
    $page = cmSignIn($this->admin)->click('[data-test="palette-button"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $page->type('[role="combobox"]', 'time zone')
        ->assertScript('Array.from(document.querySelectorAll(\'[role="group"][aria-label="Settings"] [role="option"]\')).some((option) => option.textContent.includes("Settings › General"))', true)
        ->click('[role="group"][aria-label="Settings"] [role="option"]')
        ->assertPathIs(route('settings.show', ['group' => 'general'], false))
        ->assertScript('document.activeElement !== null && document.activeElement.id === "field-timezone"', true);
});

it('keeps the Settings menu in view while a long settings page scrolls on a wide screen (#252)', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 640)
        ->navigate(route('settings.show', ['group' => 'general'], false))->assertScript(menuHeadingIs('General'), true);

    // Every box that scrolls, the page's own included, to its end: the menu's top is then measured against the window.
    $after = $page->script('() => {
        let scrolled = 0;
        for (const box of [document.scrollingElement, ...document.querySelectorAll("*")]) {
            if (box.scrollHeight > box.clientHeight + 1 && (box === document.scrollingElement || ["auto", "scroll"].includes(getComputedStyle(box).overflowY))) {
                box.scrollTop = box.scrollHeight;
                scrolled += box.scrollTop;
            }
        }

        return {scrolled, top: Math.round(document.querySelector("nav[aria-label=\"Settings\"]").getBoundingClientRect().top)};
    }');

    // The page did scroll, and the menu's top is still inside the window.
    expect($after['scrolled'])->toBeGreaterThan(0)
        ->and($after['top'])->toBeGreaterThanOrEqual(0)->toBeLessThan(640);
});

it('passes axe with the palette open on Recent and Go to, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate(route('settings.show', ['group' => 'media'], false))->assertScript(menuHeadingIs('Media'), true)
        ->navigate('/admin')->assertSeeIn('nav[aria-label="Main"]', 'People')
        ->click('[data-test="palette-button"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $page->assertScript('document.querySelector(\'[role="group"][aria-label="Recent"]\') !== null', true)
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
