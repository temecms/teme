<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Jobs\JobsFixtures;

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
    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->admin)->get(route('preferences.edit'))->assertOk();
    $this->actingAs($this->admin)->get(route('tools.page-cache.show'))->assertOk();
});

it('passes axe on Home, with the account menu open and with the menu collapsed, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->assertSee('The Garden City Ledger');
    // The scheme is applied once the page has loaded, and the header's buttons fade their colours to it.
    shellSettled($page, 'header[data-slot="app-header"] button');
    $page->assertNoAccessibilityIssues(3);

    $closed = cmSmallTargets($page);

    $page->click('[data-test="account-menu-button"]')->assertVisible('[role="menu"]');
    shellSettled($page, '[role="menu"]');
    $page->assertNoAccessibilityIssues(3);

    $open = cmSmallTargets($page);

    $page->keys('[role="menu"]', 'Escape')
        ->assertNotPresent('[role="menu"]')
        ->click('[data-test="collapse-menu"]')
        ->assertAttribute('[data-test="collapse-menu"]', 'aria-pressed', 'true');
    shellSettled($page, '[data-slot="sidebar"] > div');
    $page->assertNoAccessibilityIssues(3);

    $collapsed = cmSmallTargets($page);

    // Leaves the menu as it found it: the choice is a cookie the next run would read.
    $page->click('[data-test="collapse-menu"]')
        ->assertAttribute('[data-test="collapse-menu"]', 'aria-pressed', 'false');

    expect($closed['small'])->toBe([])
        ->and($closed['checked'])->toBeGreaterThan(0)
        ->and($open['small'])->toBe([])
        ->and($open['checked'])->toBeGreaterThan(0)
        ->and($collapsed['small'])->toBe([])
        ->and($collapsed['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('prints the site, the product, its version and its environment in the sidebar head', function (): void {
    cmSignIn($this->admin)
        ->assertSeeIn('[data-test="sidebar-brand"]', 'The Garden City Ledger')
        ->assertSeeIn('[data-test="sidebar-brand"]', 'Teme '.config('teme.version').' · '.app()->environment())
        ->assertSeeIn('[data-test="brand-mark"]', 'T');
});

it('tells a guest the product and the environment on the sign-in screen, and not the version', function (): void {
    visit('/admin/login')
        ->assertSee('Sign in')
        ->assertSeeIn('[data-test="sign-in-brand"]', 'The Garden City Ledger')
        ->assertSeeIn('[data-test="sign-in-brand"]', 'Teme · '.app()->environment())
        ->assertDontSee('Teme '.config('teme.version'))
        ->assertDontSee('Log in');
});

it('passes axe on the sign-in screen, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    $page = $scheme === 'dark' ? visit('/admin/login')->inDarkMode() : visit('/admin/login')->inLightMode();

    $page->assertSee('Sign in')->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('does not draw a follow-you banner under the header; site status stays in the top bar, passing axe in both schemes', function (string $scheme): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

    $page = cmSignIn($this->admin)
        ->assertNotPresent('[data-test="site-notice"]')
        ->assertVisible('[data-test="site-status-button"]');

    shellSettled($page, 'header[data-slot="app-header"] button');
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('shows a result as a toast with a Dismiss, passing axe and the target size, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/account/preferences')
        // A choice on Preferences is saved the moment it is made.
        ->click('[id="field-editor.smart_quotes"]')
        ->assertSee('Preferences saved.')
        ->assertVisible('[data-test="flash-toast"]')
        // Sonner keeps a toast while the pointer is over it; the run outlasts six seconds.
        ->hover('[data-test="flash-toast"]');
    shellSettled($page, '[data-test="flash-toast"]');
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    $page->click('[data-test="flash-toast-dismiss"]')
        ->assertNotPresent('[data-test="flash-toast"]');

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('sends the skip link to the main content, not the header', function (): void {
    cmSignIn($this->admin)->assertScript(<<<'JS'
        function () {
            const main = document.querySelector('#main-content');
            const header = document.querySelector('header[data-slot="app-header"]');

            return document.querySelector('a[href="#main-content"]') !== null
                && main !== null && main.tagName === 'MAIN'
                && header !== null && !main.contains(header);
        }
        JS);
});

it('opens the menu as a drawer under 768 pixels from a button named Open menu, passing axe', function (): void {
    $page = cmSignIn($this->admin)->resize(390, 844);

    $page->assertVisible('[data-sidebar="trigger"]')
        ->assertAttribute('[data-sidebar="trigger"]', 'aria-label', 'Open menu')
        ->click('[data-sidebar="trigger"]')
        ->assertScript("document.querySelector('[data-mobile=\"true\"]') !== null");
    shellSettled($page, '[data-mobile="true"]');
    $page->assertSee('The Garden City Ledger')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
});

it('frames Account with one h1 and the segmented Colors control, passing axe and the target size, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/account/preferences')
        ->assertSee('How the admin looks and behaves for you. Each choice saves as you make it.')
        ->assertSeeIn('#appearance-mode-label', 'Colours')
        ->assertVisible('[data-test="appearance-mode"] >> text="Match my computer"')
        ->assertNoAccessibilityIssues(3);

    $page->assertScript('document.querySelectorAll("h1").length === 1');

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('opens the palette from the search button', function (): void {
    cmSignIn($this->admin)
        ->click('[data-test="palette-button"]')
        ->assertVisible('[role="combobox"]');
});

it('draws the parents above a child screen, and nothing above Home', function (): void {
    $page = cmSignIn($this->admin)->assertNotPresent('[data-test="crumbs"] a');

    $page->navigate('/admin/tools/page-cache')
        ->assertVisible('[data-test="crumbs"]')
        ->assertSeeIn('[data-test="crumbs"] a', 'Tools');
});

it('lists Your account, Preferences, Notifications, Security and Sign out under Name · Role', function (): void {
    cmSignIn($this->admin)
        ->click('[data-test="account-menu-button"]')
        ->assertVisible('[role="menu"]')
        ->assertSeeIn('[role="menu"]', 'Ada Admin · Administrator')
        ->assertSeeIn('[role="menu"]', 'Your account')
        ->assertSeeIn('[role="menu"]', 'Preferences')
        ->assertSeeIn('[role="menu"]', 'Notifications')
        ->assertSeeIn('[role="menu"]', 'Security')
        ->assertSeeIn('[role="menu"]', 'Sign out')
        ->assertVisible('[data-test="logout-button"]');
});

it('shows Themes crumbs as Site / Themes, jobs idle, the bell count weight, Fold and View site icons, and hub chevrons at 1440', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, 'header[data-slot="app-header"] button');

    $page->assertVisible('[data-test="jobs-button"]')
        ->assertAttribute('[data-test="jobs-button"]', 'aria-label', 'No jobs running')
        ->assertVisible('[data-test="view-site"]')
        ->assertVisible('[data-test="collapse-menu"]')
        ->assertScript(<<<'JS'
            () => {
                const view = document.querySelector('[data-test="view-site"] svg');
                const fold = document.querySelector('[data-test="collapse-menu"] svg');
                const hub = document.querySelector('[data-test="nav-hub-tools"]');
                return view !== null && fold !== null && hub !== null;
            }
            JS);

    $page->navigate('/admin/themes');
    cmWaitUntil($page, "location.pathname === '/admin/themes'");
    shellSettled($page, '[data-test="crumbs"]');

    $page->assertScript(<<<'JS'
        () => {
            const crumbs = [...document.querySelectorAll('[data-test="crumbs"] li')]
                .map((li) => li.textContent?.replace(/\\s+/g, ' ').trim())
                .filter(Boolean);
            return crumbs.join(' / ').includes('Site') && crumbs.join(' / ').includes('Themes');
        }
        JS);

    $page->assertNoAccessibilityIssues(3);
});

it('applies a Customise menu tick at once and keeps the entry out of the sidebar', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, '[data-test="personalise-menu"]');

    $page->assertVisible('[data-test="nav-media"]')
        ->click('[data-test="personalise-menu"]');
    shellSettled($page, '[data-test="personalise-menu-panel"]');

    $page->click('[data-test="personalise-media"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="nav-media"]\') === null');

    // The menu stays open for the next tick; axe is checked once it is closed.
    $page->assertNotPresent('[data-test="nav-media"]')
        ->keys('[data-test="personalise-menu-panel"]', 'Escape')
        ->assertNotPresent('[role="menu"]');
    shellSettled($page, 'header[data-slot="app-header"] button');
    $page->assertNoAccessibilityIssues(3);
});

it('draws Account and Plugins crumbs, and opens Tools screens from the hub chevron', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, '[data-test="nav-hub-tools"]');

    $page->click('[data-test="nav-hub-tools"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="nav-page-cache"]\') !== null');
    $page->assertVisible('[data-test="nav-page-cache"]');

    $page->navigate('/admin/plugins');
    cmWaitUntil($page, "location.pathname === '/admin/plugins'");
    shellSettled($page, '[data-test="crumbs"]');
    $page->assertScript(<<<'JS'
        () => {
            const text = document.querySelector('[data-test="crumbs"]')?.innerText ?? '';
            return text.includes('Site') && text.includes('Plugins');
        }
        JS);

    $page->navigate('/admin/account/profile');
    cmWaitUntil($page, "location.pathname === '/admin/account/profile'");
    shellSettled($page, '[data-test="crumbs"]');
    $page->assertScript(<<<'JS'
        () => {
            const text = document.querySelector('[data-test="crumbs"]')?.innerText ?? '';
            return text.includes('Account') && text.includes('Profile');
        }
        JS)
        ->assertNoAccessibilityIssues(3);
});

it('marks Home current on Home only, not on every other admin page', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, '[data-test="nav-dashboard"]');
    $page->assertAttribute('[data-test="nav-dashboard"]', 'aria-current', 'page');

    foreach (['/admin/themes', '/admin/tools/page-cache'] as $path) {
        $page->navigate($path);
        cmWaitUntil($page, "location.pathname === '{$path}'");
        shellSettled($page, '[data-test="crumbs"]');
        $page->assertScript('document.querySelector(\'[data-test="nav-dashboard"]\')?.getAttribute(\'aria-current\') === null');
    }
});

it('draws the menu count as an accent pill, the bell count by its weight and the account as a 32 pixel circle, at 1440', function (): void {
    $item = cmItem($this->admin, 'Harbour news');
    cmNext($item, 'pending');
    JobsFixtures::inboxItem($this->admin->id);
    JobsFixtures::inboxItem($this->admin->id, ['created_at' => now()->subHour()]);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, 'header[data-slot="app-header"] button');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="inbox-bell"]\')?.getAttribute(\'aria-label\') === \'Inbox, 2 unread\'');

    $page->assertScript(<<<'JS'
        () => {
            const pill = document.querySelector('[data-test="nav-comments"]')
                ?.closest('[data-sidebar="menu-item"]')
                ?.querySelector('[data-sidebar="menu-badge"]');
            if (!pill) {
                return false;
            }
            const style = getComputedStyle(pill);
            const box = pill.getBoundingClientRect();
            return pill.textContent.includes('1')
                && style.backgroundColor !== 'rgba(0, 0, 0, 0)'
                && parseFloat(style.borderTopLeftRadius) >= box.height / 2;
        }
        JS)
        ->assertScript(<<<'JS'
        () => {
            const bell = document.querySelector('[data-test="inbox-bell"]');
            const count = bell?.querySelector('span');
            return count !== null && count.textContent.trim() === '2'
                && parseInt(getComputedStyle(count).fontWeight, 10) >= 500;
        }
        JS)
        ->assertScript(<<<'JS'
        () => {
            const box = document.querySelector('[data-test="account-menu-button"] [data-slot="avatar"]')?.getBoundingClientRect();
            return box !== undefined && Math.round(box.width) === 32 && Math.round(box.height) === 32;
        }
        JS)
        ->assertNoAccessibilityIssues(3);
});

it('opens the account menu at 1440 and passes axe', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, 'header[data-slot="app-header"] button');

    $page->click('[data-test="account-menu-button"]')->assertVisible('[role="menu"]');
    shellSettled($page, '[role="menu"]');
    $page->assertSeeIn('[role="menu"]', 'Ada Admin · Administrator')
        ->assertNoAccessibilityIssues(3);
});

it('keeps the Customise menu open after a tick', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900);
    shellSettled($page, '[data-test="personalise-menu"]');

    $page->click('[data-test="personalise-menu"]');
    shellSettled($page, '[data-test="personalise-menu-panel"]');
    $page->click('[data-test="personalise-media"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="nav-media"]\') === null');

    $page->assertVisible('[data-test="personalise-menu-panel"]');
});
