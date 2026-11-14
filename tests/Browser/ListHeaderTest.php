<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ade Author'])->assignRole('author');
    $this->transport = taxonomyTerm('category', 'Transport');
    $this->roads = taxonomyTerm('category', 'Roads', $this->transport->id);

    // A post published in September; the author's draft and then the editor's in October.
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));
    $this->harbour = typesDraft($this->editor, 'post', 'Harbour news', [$this->transport->id]);
    listsPublish($this->harbour, $this->editor);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    $this->market = typesDraft($this->author, 'post', 'Market day', [$this->roads->id]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00:00', 'UTC'));
    $this->ferry = lifecycleDraft($this->editor, 'Ferry fares');
    $this->travelBack();

    // The filter line's buttons as they read, "Label: value".
    $this->buttons = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"list-filters\"] button[data-test^=\"filter-\"]')].map((button) => button.innerText.trim()).join('|')");

    // The rows' titles, top to bottom.
    $this->titles = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"row-title\"]')].map((title) => title.textContent.trim()).join('|')");

    // The Date panel's entries: "all" or the month, then its count.
    $this->months = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"filter-date-panel\"] [data-test=\"date-all\"], [data-test=\"filter-date-panel\"] [data-test=\"date-month\"]')].map((link) => (link.dataset.month ?? 'all') + ':' + (link.querySelector('[data-test=\"date-count\"]')?.textContent.trim() ?? '')).join('|')");

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('puts Screen options and Add new on the title line, and every filter in one labelled line, with no Filter button (I-3129)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"list-filters\"]') !== null")
        ->assertScript("(() => { const header = document.querySelector('h1').closest('header'); return header.contains(document.querySelector('[data-test=\"screen-options\"]')) && header.contains(document.querySelector('[data-test=\"add-new\"]')); })()")
        ->assertScript("[...document.querySelectorAll('button')].some((button) => button.textContent.trim() === 'Filter')", false)
        ->assertScript("document.querySelectorAll('input[type=\"month\"], input[type=\"date\"]').length", 0)
        ->assertScript("document.querySelector('[data-test=\"clear-filters\"]') === null");

    expect(($this->buttons)($page))->toBe('Author: All|Category: All|Date: All dates');
});

it('applies a category the moment it is chosen, names it on its button, and clears it with Clear filters (#120)', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[data-test="filter-category"]')
        ->click('[data-test="filter-category-panel"] >> text="Roads"');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('category') === '{$this->roads->id}' && document.querySelector('[data-test=\"clear-filters\"]') !== null");

    expect(($this->buttons)($page))->toBe('Author: All|Category: Roads|Date: All dates')
        ->and(($this->titles)($page))->toBe('Market day');

    $page->click('[data-test="clear-filters"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('category') === null && document.querySelector('[data-test=\"clear-filters\"]') === null");

    expect(($this->titles)($page))->toBe('Ferry fares|Market day|Harbour news');
});

it('applies an author at once, keeping the category in force', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate("/admin/posts?category={$this->transport->id}")
        ->click('[data-test="filter-author"]')
        ->click('[data-test="filter-author-panel"] >> text="Eve Editor"');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('author') === '{$this->editor->id}' && new URLSearchParams(window.location.search).get('category') === '{$this->transport->id}'");

    expect(($this->buttons)($page))->toBe('Author: Eve Editor|Category: Transport|Date: All dates')
        ->and(($this->titles)($page))->toBe('Harbour news');
});

it('lists the months that hold posts, newest first with their counts, and filters by one the moment it is chosen (I-3131)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts')->click('[data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"] [data-test=\"date-month\"]') !== null && document.activeElement?.dataset.test === 'date-all'")
        ->assertScript("document.querySelector('[data-test=\"date-hint\"]').textContent.trim()", 'By last update. On the Published tab, by date published.')
        ->assertScript("[...document.querySelectorAll('[data-test=\"filter-date-panel\"] [data-test=\"date-name\"]')].map((name) => name.textContent.trim()).join('|')", 'All dates|October 2026|September 2026')
        ->assertAttribute('[data-test="filter-date"]', 'aria-expanded', 'true');

    expect(($this->months)($page))->toBe('all:3|2026-10:2|2026-09:1');

    $page->click('[data-test="filter-date-panel"] [data-month="2026-10"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('month') === '2026-10' && document.querySelectorAll('[data-test=\"row-title\"]').length === 2");

    expect(($this->buttons)($page))->toBe('Author: All|Category: All|Date: October 2026')
        ->and(($this->titles)($page))->toBe('Ferry fares|Market day');
});

it('counts the Published tab by the date each post was published', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts?tab=published')->click('[data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"] [data-test=\"date-month\"]') !== null")
        ->assertScript("document.querySelector('[data-test=\"date-hint\"]').textContent.trim()", 'By date published.');

    expect(($this->months)($page))->toBe('all:1|2026-09:1');
});

it('chooses an exact range with labelled From and To, goes back to the months, and says the range on its button', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[data-test="filter-date"]')
        ->click('[data-test="date-choose"]');

    cmWaitUntil($page, "document.querySelector('#date-from') !== null")
        ->assertScript("[...document.querySelectorAll('[data-test=\"filter-date-panel\"] label')].map((label) => label.textContent.trim() + '>' + label.htmlFor).join('|')", 'From>date-from|To>date-to')
        ->click('[data-test="date-back"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"date-month\"]') !== null && document.querySelector('#date-from') === null");

    $page->click('[data-test="date-choose"]')
        ->type('#date-from', '2026-09-01')
        ->type('#date-to', '2026-09-30')
        ->click('[data-test="date-apply"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('from') === '2026-09-01' && new URLSearchParams(window.location.search).get('to') === '2026-09-30' && document.querySelectorAll('[data-test=\"row-title\"]').length === 1");

    expect(($this->buttons)($page))->toBe('Author: All|Category: All|Date: 1–30 Sep 2026')
        ->and(($this->titles)($page))->toBe('Harbour news');
});

it('searches on Enter, and searches the text at once when In text is chosen with words typed', function (): void {
    docSave($this->harbour, $this->editor, docWith(docText('The lifeboat crew marked fifty years.')));
    listsPublish($this->harbour->refresh(), $this->editor);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->type('[data-test="list-search"]', 'lifeboat')
        ->keys('[data-test="list-search"]', 'Enter');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('search') === 'lifeboat' && document.querySelectorAll('[data-test=\"row-title\"]').length === 0");

    $page->click('[aria-label="Search in"]')->click('[role="option"] >> text="In text"');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('in') === 'body' && document.querySelectorAll('[data-test=\"row-title\"]').length === 1");

    expect(($this->titles)($page))->toBe('Harbour news');
});

it('opens Screen options as a popover under its button, not a modal, its first field taking focus, and Escape closes it, back on the button (I-3135)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"screen-options\"]') !== null");
    $width = $page->script('document.documentElement.clientWidth');
    $page->click('[data-test="screen-options"]');

    // The popover is portalled: it is found by its own hook, never inside the button's parent.
    cmWaitUntil($page, "document.querySelector('[data-test=\"screen-options-panel\"]') !== null && document.querySelector('[data-test=\"screen-options-panel\"]').contains(document.activeElement)")
        ->assertScript("(() => { const panel = document.querySelector('[data-test=\"screen-options-panel\"]'); return panel.getAttribute('aria-modal') === null && getComputedStyle(document.body).pointerEvents !== 'none' && panel.getAttribute('aria-label') === 'Screen options'; })()")
        ->assertScript('document.activeElement.id', 'column-featured')
        ->assertAttribute('[data-test="screen-options"]', 'aria-expanded', 'true')
        ->assertAttribute('[data-test="screen-options"]', 'data-state', 'open')
        ->assertScript("(() => { const panel = document.querySelector('[data-test=\"screen-options-panel\"]').getBoundingClientRect(); return panel.top >= document.querySelector('[data-test=\"screen-options\"]').getBoundingClientRect().bottom && panel.right <= window.innerWidth; })()")
        ->assertScript("[...document.querySelectorAll('[data-test=\"screen-options-panel\"] [id^=\"column-\"]')].map((box) => box.id).join('|')", 'column-featured|column-author|column-taxonomy-category|column-taxonomy-tag|column-comments|column-date')
        ->assertScript('document.documentElement.clientWidth', $width)
        ->keys('#column-author', 'Escape');

    cmWaitUntil($page, "document.querySelector('[data-test=\"screen-options-panel\"]') === null && document.activeElement === document.querySelector('[data-test=\"screen-options\"]')");
});

it('shows the Featured image column once Screen options turns it on: a small thumbnail, or a mark of the same size (I-3120)', function (): void {
    $image = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg');
    docSave($this->ferry, $this->editor, docWith(docText('Fares rise in November.')), [
        BuiltInFields::FEATURED_IMAGE => ['id' => $image->id, 'alt' => '', 'decorative' => false],
    ]);

    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"row-title\"]').length === 3")
        ->assertScript("document.querySelector('[data-test=\"featured-thumb\"], [data-test=\"featured-none\"]') === null");

    $page->click('[data-test="screen-options"]')
        ->click('#column-featured')
        ->click('[data-test="screen-options-panel"] >> text="Apply"');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"featured-thumb\"]').length === 1 && document.querySelectorAll('[data-test=\"featured-none\"]').length === 2")
        ->assertScript("(() => { const thumb = document.querySelector('[data-test=\"featured-thumb\"]'); const box = thumb.getBoundingClientRect(); const none = document.querySelector('[data-test=\"featured-none\"]').getBoundingClientRect(); return thumb.getAttribute('alt') === '' && thumb.getAttribute('loading') === 'lazy' && Math.round(box.width) === 48 && Math.round(box.height) === 32 && Math.round(none.width) === 48 && Math.round(none.height) === 32; })()")
        ->assertScript("[...document.querySelectorAll('tbody tr')].every((row) => row.children[1].querySelector('[data-test^=\"featured-\"]') !== null)")
        ->assertScript("document.querySelector('[data-test=\"featured-none\"]').textContent.trim()", 'No featured image');

    expect(PreferencesFor::user($this->editor->refresh())['lists.post.columns'])->toContain('featured');
});

it('closes an open filter when another opens or the pointer presses outside, so only one is ever open', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts')->click('[data-test="filter-author"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-author-panel\"]') !== null");
    $page->click('[data-test="filter-category"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-author-panel\"]') === null && document.querySelector('[data-test=\"filter-category-panel\"]') !== null");
    $page->click('[data-test="list-search"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-category-panel\"]') === null");
});

it('turns the filters into a sideways chip line on a phone, each chip opening a sheet, with no sideways scroll on the page', function (): void {
    $page = cmSignIn($this->editor)->resize(390, 844)->navigate('/admin/posts?from=2026-09-01&to=2026-10-31');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-chips\"]') !== null")
        ->assertScript("(() => { const line = document.querySelector('[data-test=\"filter-chips\"]'); return getComputedStyle(line).overflowX === 'auto' && line.scrollWidth > line.clientWidth; })()")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("document.querySelector('[data-test=\"add-new\"]').textContent.trim()", 'Add new')
        ->click('[data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"] [data-test=\"date-month\"]') !== null");
    cmWaitUntil($page, "[...document.querySelectorAll('[data-slot=\"sheet-content\"], [data-slot=\"sheet-overlay\"]')].every((element) => element.getAnimations().length === 0)");

    $page->click('[data-slot="sheet-content"] [data-month="2026-10"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('month') === '2026-10' && new URLSearchParams(window.location.search).get('from') === null");
});

it('passes axe with the Date list open and with Screen options open, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)->navigate('/admin/posts')->click('[data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"] [data-test=\"date-month\"]') !== null");
    $page->assertNoAccessibilityIssues(3);
    $date = cmSmallTargets($page);

    $page->keys('[data-test="date-all"]', 'Escape')->click('[data-test="screen-options"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"screen-options-panel\"]') !== null && document.querySelector('[data-test=\"filter-date-panel\"]') === null");
    $page->assertNoAccessibilityIssues(3);
    $screen = cmSmallTargets($page);

    expect($date['small'])->toBe([])
        ->and($date['checked'])->toBeGreaterThan(0)
        ->and($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
