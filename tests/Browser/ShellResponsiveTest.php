<?php

declare(strict_types=1);

use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
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

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('folds the full menu to the rail at full width and back, remembering the choice', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 264');

    $page->click('[data-test="collapse-menu"]');
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 68');

    $page->click('[data-test="collapse-menu"]');
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 264');
});

it('shows the rail between 768 and 1023 pixels, and slides the whole menu over the page from Open the menu, closed by Escape', function (): void {
    $page = cmSignIn($this->admin)->resize(900, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 68');

    $page->click('[data-test="open-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="menu-overlay"]\') !== null && document.querySelector(\'[data-test="menu-overlay"]\').contains(document.activeElement)');
    shellSettled($page, '[data-test="menu-overlay"]');
    $page->assertNoAccessibilityIssues(3)
        ->keys('[data-test="menu-overlay"]', 'Escape');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="menu-overlay"]\') === null && document.activeElement === document.querySelector(\'[data-test="open-menu"]\')');
});

it('gathers jobs, the site status and the inbox behind one button under 768 pixels', function (): void {
    $page = cmSignIn($this->admin)->resize(390, 844);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="status-button"]\') !== null');

    $page->click('[data-test="status-button"]');
    shellSettled($page, '[data-test="status-sheet"]');
    $page->assertSeeIn('[data-test="status-sheet"]', 'Site status')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])->and($targets['checked'])->toBeGreaterThan(0);
});

it('draws a planned entry with its Soon tag, and says so instead of opening anything', function (): void {
    app(NavigationRegistry::class)->register(new NavEntry('newsletter', 'Newsletter', 'mail', NavSection::Content, 900, soon: true));

    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="nav-newsletter"]\') !== null');

    $page->assertSeeIn('[data-test="nav-newsletter"]', 'Soon')
        ->assertAttribute('[data-test="nav-newsletter"]', 'aria-disabled', 'true');
    // Playwright waits for an aria-disabled element to be enabled before a click, so this one is forced.
    $page->page()->locator('[data-test="nav-newsletter"]')->click(['force' => true]);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="flash-toast"]\')?.innerText.includes("Newsletter is planned and not built yet.") === true && location.pathname === "/admin"');
});

it('shows the site status in the top bar, the theme that cannot be used as a problem, in both schemes', function (string $scheme): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $this->actingAs($this->admin)->get('/admin')->assertOk();

    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="site-status-button"]\')?.getAttribute("aria-label").startsWith("Site status: ") === true');

    $page->click('[data-test="site-status-button"]');
    shellSettled($page, '[data-test="site-status-panel"]');
    $page->assertSeeIn('[data-test="site-status-row"]:first-child', 'Problem')
        ->assertSeeIn('[data-test="site-status-row"]:first-child', '"broken"')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

it('keeps a section folded at full width drawn as icons on the rail, and Tab never lands on a hidden fold heading', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 900);
    $group = 'document.querySelector(\'[aria-label="Show or hide Content"]\').closest(\'[data-slot="sidebar-group"]\')';
    cmWaitUntil($page, 'document.querySelector(\'[aria-label="Show or hide Content"]\') !== null');

    $page->click('[aria-label="Show or hide Content"]');
    cmWaitUntil($page, "{$group}.querySelectorAll('[data-slot=\"sidebar-menu-button\"]').length === 0");

    $page->resize(900, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 68');
    cmWaitUntil($page, "[...{$group}.querySelectorAll('[data-slot=\"sidebar-menu-button\"]')].some((button) => button.checkVisibility())");

    // Every place Tab lands while it walks the rail, from the brand to the menu's end.
    $page->script('() => { window.landed = []; document.addEventListener("focusin", (event) => window.landed.push(event.target.getAttribute("data-slot") ?? "")); }');
    $page->page()->locator('[data-test="sidebar-brand"]')->focus();

    for ($press = 0; $press < 40 && $page->script('() => document.querySelector(\'[data-slot="sidebar"]\').contains(document.activeElement)'); $press++) {
        $page->page()->locator(':focus')->press('Tab');
    }

    expect($page->script('() => window.landed'))->not->toBeEmpty()->not->toContain('sidebar-group-fold');
});

/** Starts recording every address the page asks for from now on, through XHR or fetch. */
function shellRecordRequests(mixed $page): void
{
    $page->script('() => {
        window.asked = [];
        const open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url, ...rest) { window.asked.push(String(url)); return open.call(this, method, url, ...rest); };
        const send = window.fetch;
        window.fetch = (input, ...rest) => { window.asked.push(String(input instanceof Request ? input.url : input)); return send(input, ...rest); };
    }');
}

/** The status sheet's section headings, as written. */
function shellSheetSections(mixed $page): array
{
    return $page->script('() => [...document.querySelectorAll(\'[data-test="status-sheet"] h3\')].map((heading) => heading.textContent)');
}

it('leaves Jobs out of the phone status sheet, and asks for none, for someone who has never had a job', function (): void {
    $page = cmSignIn($this->admin)->resize(390, 844);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="status-button"]\') !== null');
    shellRecordRequests($page);

    $page->click('[data-test="status-button"]');
    shellSettled($page, '[data-test="status-sheet"]');
    cmWaitUntil($page, 'window.asked.some((url) => url.includes("/admin/inbox/latest"))');

    expect(shellSheetSections($page))->toBe(['Site status', 'Notifications'])
        ->and(collect($page->script('() => window.asked'))->filter(fn (string $url): bool => str_contains($url, '/admin/my-jobs')))->toBeEmpty();
});

it('leaves Jobs and Notifications out of the phone status sheet, and asks for neither, for someone not yet verified on their profile', function (): void {
    $waiting = User::factory()->unverified()->create()->assignRole('author');

    $page = visit('/admin/login')
        ->type('email', $waiting->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]');
    $page->navigate('/admin/account/profile')->resize(390, 844);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="status-button"]\') !== null');
    shellRecordRequests($page);

    $page->click('[data-test="status-button"]');
    shellSettled($page, '[data-test="status-sheet"]');

    expect(shellSheetSections($page))->toBe(['Site status'])
        ->and(collect($page->script('() => window.asked'))->filter(fn (string $url): bool => str_contains($url, '/admin/my-jobs') || str_contains($url, '/admin/inbox')))->toBeEmpty();
});

it('does not open the menu over the page again after the screen widens and narrows back', function (): void {
    $page = cmSignIn($this->admin)->resize(900, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 68');

    $page->click('[data-test="open-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="menu-overlay"]\') !== null');

    $page->resize(1280, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 264');
    // The person carries on in the page, away from the menu.
    $page->script('() => document.activeElement.blur()');

    $page->resize(900, 900);
    cmWaitUntil($page, 'Math.round(document.querySelector(\'[data-slot="sidebar-gap"]\').getBoundingClientRect().width) === 68');

    expect($page->script('() => document.querySelector(\'[data-test="menu-overlay"]\') === null && document.activeElement === document.body'))->toBeTrue();
});
