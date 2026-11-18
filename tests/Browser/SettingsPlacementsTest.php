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

    // Warm the screens before the browser's first visit.
    foreach (['/admin', '/admin/settings/general', '/admin/settings/writing', '/admin/settings/reading', '/admin/settings/media', '/admin/settings/people'] as $path) {
        $this->actingAs($this->admin)->get($path)->assertOk();
    }
});

it('opens Writing and People from the Settings menu, each under its own heading, passing axe in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/general')
        ->click('nav[aria-label="Settings"] a[href="/admin/settings/writing"]');

    cmWaitUntil($page, 'document.querySelector("main h1")?.textContent === "Writing"');

    $page->assertPathIs('/admin/settings/writing')
        ->assertScript('document.querySelector("main h1")?.textContent === "Writing"', true)
        ->assertAttribute('nav[aria-label="Settings"] a[aria-current="page"]', 'href', '/admin/settings/writing')
        ->assertNoAccessibilityIssues(3);

    $page->click('nav[aria-label="Settings"] a[href="/admin/settings/people"]');

    cmWaitUntil($page, 'document.querySelector("main h1")?.textContent === "People"');

    $page->assertPathIs('/admin/settings/people')
        ->assertScript('document.querySelector("main h1")?.textContent === "People"', true)
        // Each panel names its heading with an id of its own, so the heading is found by its words.
        ->assertScript('[...document.querySelectorAll("main h2")].some((heading) => heading.textContent === "Sign-in rules")', true)
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

it('finds Keep trash for, and lands on its field on Writing', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/general')
        ->type('[data-test="find-setting"]', 'keep trash');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="find-setting-list"]\')?.textContent.includes("Keep trash for")');

    $page->click('[data-test="find-setting-item"][data-key="content.trash_days"]');

    cmWaitUntil($page, 'document.activeElement !== null && document.activeElement.id === "field-trash_days"');

    $page->assertPathIs('/admin/settings/writing')
        ->assertScript('document.activeElement !== null && document.activeElement.id === "field-trash_days"', true);
});

it('sends the old Content and Security addresses to Writing and People', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/content');

    cmWaitUntil($page, 'location.pathname === "/admin/settings/writing"');

    $page->assertPathIs('/admin/settings/writing')->navigate('/admin/settings/security');

    cmWaitUntil($page, 'location.pathname === "/admin/settings/people"');

    $page->assertPathIs('/admin/settings/people');
});

it('chooses a page as the home page on Reading, and keeps it', function (): void {
    addrPublished($this->admin, 'Welcome', 'page');

    $page = cmSignIn($this->admin)->navigate('/admin/settings/reading');

    cmWaitUntil($page, 'document.querySelector("main h1")?.textContent === "Reading"');

    $page->click('#front-shows-page')
        ->type('#field-front_page_id', 'Welc');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="page-choice"]\') !== null');

    $page->click('[data-test="page-choice"]');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="home-page"]\')?.textContent.includes("Chosen: Welcome")');

    $page->click('[data-test="settings-save"]');

    // The toast says "Settings saved. Moving every address in the background."
    cmWaitUntil($page, 'document.body.textContent.includes("Settings saved.")');

    $page->assertPathIs('/admin/settings/reading')
        ->navigate('/admin/settings/reading');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="home-page"]\')?.textContent.includes("Chosen: Welcome")');

    $page->assertNoAccessibilityIssues(3);
});

it('draws Reading\'s default-number note straight after Items per page, before the types on the home page (I-3306)', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/reading');

    cmWaitUntil($page, 'document.querySelector("main h1")?.textContent === "Reading"');

    // Each one follows the one before it in the page, as drawn.
    $page->assertScript('(() => {
        const perPage = document.getElementById("field-posts_per_page");
        const note = document.querySelector(\'[data-test="theme-per-page"]\');
        const types = document.querySelector(\'[id^="field-home_types"]\');
        const follows = (a, b) => a !== null && b !== null && (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;

        return follows(perPage, note) && follows(note, types);
    })()', true);
});

it('reads the brand colour\'s contrast as it is typed, and saves it on General', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/general');

    cmWaitUntil($page, 'document.querySelector("#field-accent") !== null');

    $page->type('#field-accent', '#ffeb3b');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="brand-colour"]\')?.textContent.includes("The brand colour reaches only")');

    $page->type('#field-accent', '#0b5394');

    cmWaitUntil($page, 'document.querySelector("#brand-colour-readout")?.textContent.includes("on light ✓")');

    $page->click('[data-test="settings-save"]');

    cmWaitUntil($page, 'document.body.textContent.includes("Settings saved.")');

    $page->assertPathIs('/admin/settings/general')
        ->navigate('/admin/settings/general');

    cmWaitUntil($page, 'document.querySelector("#field-accent")?.value === "#0b5394"');
});

it('draws General with its Brand colour, passing axe and the target size, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/general');

    cmWaitUntil($page, '[...document.querySelectorAll("main h2")].some((heading) => heading.textContent === "Brand colour")');

    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('says on Media which image widths Teme makes, passing axe', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/media');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="image-widths"]\')?.textContent.includes("320, 640, 960, 1280, 1920 and 2560 pixels wide")');

    $page->assertNoAccessibilityIssues(3);
});
