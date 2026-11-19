<?php

declare(strict_types=1);

use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // Fixture Good, in use, declares a region, settings and two places;
    // Fixture Quiet declares none; Fixture Broken asks for another Teme. Teme
    // Default, a Composer package, is installed too, listed after the theme in use.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet'), base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('good');
    // As on a site that chose its theme on the screen: the writer's version,
    // not themeActivate()'s 0, which a cleared draft would take for a row
    // made only for the draft, and delete.
    DB::table('settings')->where('group_key', 'design')->update(['version' => 1]);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    regionCreate($this->admin);

    // Waits for words on the page: the plugin's assertSee() looks once.
    $this->textShown = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/themes')->assertOk();
});

it('opens Themes from the main menu, current there, and Settings no longer lists Design', function (): void {
    $page = cmSignIn($this->admin)->click('[data-slot="sidebar-menu-button"][href="/admin/themes"]');

    cmWaitUntil($page, "location.pathname === '/admin/themes' && document.querySelector('main h1')?.textContent === 'Themes'");

    $page->assertAttribute('[data-slot="sidebar-menu-button"][href="/admin/themes"]', 'data-active', 'true')
        ->navigate('/admin/settings/general');

    cmWaitUntil($page, "document.querySelector('nav[aria-label=\"Settings\"]') !== null");

    $page->assertScript("[...document.querySelectorAll('nav[aria-label=\"Settings\"] [href]')].every((link) => link.textContent.trim() !== 'Design')");
});

it('previews a theme from its row, focusing the panel, with no foot bar, and stops previewing with nothing left to save', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-preview\"]') !== null");

    $page->click('[data-test="theme-default"] [data-test="theme-preview"]');

    cmWaitUntil($page, "document.activeElement?.id === 'theme-preview-heading'");

    $page->assertScript("document.querySelector('#theme-preview-heading')?.textContent?.trim() === 'Previewing Teme Default 0.1.0'")
        ->assertScript("document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-state\"]')?.textContent === 'Previewing'")
        // While a theme is previewed, the panel's buttons are the draft's two ends.
        ->assertScript("document.querySelector('[aria-label=\"Unpublished design changes\"]') === null")
        ->click('[data-test="theme-default"] [data-test="theme-settings-link"]');

    cmWaitUntil($page, "location.hash === '#theme-settings' && document.querySelector('#theme-settings #design-show_author_box') !== null");

    // A change to the previewed theme's settings, waiting for its save, then Stop at once.
    $page->click('#design-show_author_box')
        ->click('[data-test="theme-stop"]');

    ($this->textShown)($page, 'Stopped previewing Teme Default.');
    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-preview-panel\"]') === null");

    // Past the page's 800 ms save delay: a timer that outlived Stop would draft the change now.
    $page->wait(1.5);

    $page->assertScript("document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-state\"]')?.textContent === 'Available'");

    expect(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeFalse();
});

it('passes axe on Themes in both schemes: each theme with its state, and the regions the theme in use places', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-regions\"] [data-test=\"theme-region\"]') !== null");

    // The theme in use first, then Teme Default, Fixture Quiet and Fixture Broken as found.
    $page->assertScript("[...document.querySelectorAll('[data-test=\"theme-state\"]')].map((chip) => chip.textContent).join(', ') === 'Active, Available, Available, Incompatible'")
        ->assertSeeIn('[data-test="theme-regions"]', 'Callout, Button · At most 5 blocks')
        ->assertSeeIn('[data-test="theme-regions"]', 'Draft')
        ->assertSeeIn('[data-test="theme-regions-unplaced"]', 'Nothing from other themes.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('passes axe while previewing in both schemes, with the previewed theme\'s regions and what it would leave unplaced', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-quiet\"] [data-test=\"theme-preview\"]') !== null");

    $page->click('[data-test="theme-quiet"] [data-test="theme-preview"]');

    cmWaitUntil($page, "document.activeElement?.id === 'theme-preview-heading' && document.querySelector('[data-test=\"theme-preview-regions\"]') !== null");

    $page->assertSeeIn('[data-test="theme-preview-regions"]', 'Fixture Quiet declares no regions.')
        ->assertSeeIn('[data-test="theme-preview-regions-unplaced"]', 'From Fixture Good · Draft')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('activates a previewed theme with a change made a moment before, saying it is live, and lists it first as Active', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-preview\"]') !== null");

    $page->click('[data-test="theme-default"] [data-test="theme-preview"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-settings-link\"]') !== null");

    $page->click('[data-test="theme-default"] [data-test="theme-settings-link"]');

    cmWaitUntil($page, "location.hash === '#theme-settings' && document.querySelector('[data-test=\"theme-activate\"]') !== null && document.querySelector('#theme-settings #design-show_author_box') !== null");

    // Teme Default shows the author's box by default; the change is still waiting for its save when Activate is pressed.
    $page->click('#design-show_author_box')
        ->click('[data-test="theme-activate"]');

    // Teme Default places one region, which nobody has filled for it.
    ($this->textShown)($page, 'Teme Default is live. 1 region stays empty until you fill it in Content › Regions.');
    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-preview-panel\"]') === null");

    cmNavigate($page, '/admin/themes');
    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-list\"]') !== null");

    $page->assertScript("document.querySelector('[data-test=\"theme-list\"]')?.firstElementChild?.getAttribute('data-test') === 'theme-default'")
        ->assertScript("document.querySelector('[data-test=\"theme-default\"] [data-test=\"theme-state\"]')?.textContent === 'Active'");

    expect(json_decode((string) DB::table('settings')->where('group_key', 'theme.default')->value('overrides'), true))->toMatchArray(['show_author_box' => false])
        ->and(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeFalse();
});

it('takes the active theme\'s row down to its settings on Themes, headed by its name, with the brand colour left to General', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"theme-good\"] [data-test=\"theme-settings-link\"]') !== null");

    // The note to General stands on the list, whatever the chosen theme.
    $page->assertScript("document.body.innerText.includes('The site’s name, tagline and brand colour stay when you change theme.')")
        ->click('[data-test="theme-good"] [data-test="theme-settings-link"]');

    cmWaitUntil($page, "location.pathname === '/admin/themes' && location.hash === '#theme-settings'");

    $page->assertScript("document.querySelector('#theme-settings-heading')?.textContent === 'Fixture Good settings'")
        ->assertSeeIn('[data-test="design-menus"]', 'A menu you choose here shows once you publish')
        ->assertSeeIn('[data-test="theme-settings"]', 'Colors, fonts and spacing, with a live preview, come with the Appearance screen.');
});

it('keeps Themes inside the page at 375 pixels: one column of cards, nothing scrolling sideways, every target 24 pixels or more', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 812), '/admin/themes');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"theme-list\"] > li').length === 4");

    $sizes = $page->script(<<<'JS'
        () => {
            const view = document.querySelector('main');
            const lefts = [...document.querySelectorAll('[data-test="theme-list"] > li')].map((card) => Math.round(card.getBoundingClientRect().left));

            return {
                page: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
                view: view.scrollWidth <= view.clientWidth,
                columns: new Set(lefts).size,
            };
        }
        JS);

    expect($sizes)->toBe(['page' => 0, 'view' => true, 'columns' => 1]);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
});

it('asks before discarding the unpublished design changes, and discards on Discard changes', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));

    $page = cmSignIn($this->admin)->navigate('/admin/themes');

    cmWaitUntil($page, "document.querySelector('[aria-label=\"Unpublished design changes\"]') !== null");

    $page->click('[aria-label="Unpublished design changes"] [data-test="design-discard"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"design-discard-dialog\"]') !== null");
    shellSettled($page, '[data-test="design-discard-dialog"]');

    expect(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeTrue();

    $page->click('Keep them');

    cmWaitUntil($page, "document.querySelector('[data-test=\"design-discard-dialog\"]') === null");

    expect(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeTrue();

    $page->click('[aria-label="Unpublished design changes"] [data-test="design-discard"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"design-discard-dialog\"]') !== null");
    shellSettled($page, '[data-test="design-discard-dialog"]');

    $page->click('[data-test="design-discard-confirm"]');

    ($this->textShown)($page, 'Unpublished design changes discarded.');

    expect(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeFalse();
});
