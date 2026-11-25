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

it('draws the banner under the header as a labelled region, passing axe and the target size, in both schemes', function (string $scheme): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

    $page = cmSignIn($this->admin)
        ->assertVisible('[data-test="site-notice"]')
        ->assertAttribute('[data-test="site-notice"]', 'role', 'region')
        ->assertAttribute('[data-test="site-notice"]', 'aria-label', 'Site notice');

    $page->assertScript(<<<'JS'
        function () {
            const header = document.querySelector('header[data-slot="app-header"]');
            const banner = document.querySelector('[data-test="site-notice"]');

            return header !== null && banner !== null && header.nextElementSibling === banner;
        }
        JS);
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

it('lists Profile, Security, Preferences and Sign out under the person\'s name and email', function (): void {
    cmSignIn($this->admin)
        ->click('[data-test="account-menu-button"]')
        ->assertVisible('[role="menu"]')
        ->assertSeeIn('[role="menu"]', $this->admin->email)
        ->assertSeeIn('[role="menu"]', 'Profile')
        ->assertSeeIn('[role="menu"]', 'Security')
        ->assertSeeIn('[role="menu"]', 'Preferences')
        ->assertSeeIn('[role="menu"]', 'Sign out')
        ->assertVisible('[data-test="logout-button"]');
});
