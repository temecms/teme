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

    // The filter line's filters as they read, "Label: value": a Combobox by its label and its button, the Date filter by its button.
    $this->buttons = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"list-filters\"] [data-test^=\"filter-\"]')].map((filter) => filter.matches('button') ? filter.innerText.trim() : filter.querySelector('label').textContent.trim() + ': ' + filter.querySelector('[data-slot=\"combobox-trigger\"]').textContent.trim()).join('|')");

    // The rows' titles, top to bottom.
    $this->titles = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"row-title\"]')].map((title) => title.textContent.trim()).join('|')");

    // The Date panel's entries: "all" or the month, then its count.
    $this->months = fn (mixed $page): string => $page->script("[...document.querySelectorAll('[data-test=\"filter-date-panel\"] [data-test=\"date-all\"], [data-test=\"filter-date-panel\"] [data-test=\"date-month\"]')].map((link) => (link.dataset.month ?? 'all') + ':' + (link.querySelector('[data-test=\"date-count\"]')?.textContent.trim() ?? '')).join('|')");

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('puts New in the page header and Columns at the end of the filter line, every filter in that one labelled line, with no Filter button (I-3129)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"list-filters\"]') !== null")
        ->assertScript("document.querySelector('h1').closest('header').contains(document.querySelector('[data-test=\"add-new\"]'))")
        ->assertScript("document.querySelector('[data-test=\"list-filters\"]').lastElementChild.contains(document.querySelector('[data-test=\"columns\"]'))")
        ->assertScript("[...document.querySelectorAll('button')].some((button) => button.textContent.trim() === 'Filter')", false)
        ->assertScript("document.querySelectorAll('input[type=\"month\"], input[type=\"date\"]').length", 0)
        ->assertScript("document.querySelector('[data-test=\"clear-filters\"]') === null");

    expect(($this->buttons)($page))->toBe('Author: All authors|Category: All categories|Date: All dates')
        ->and($page->script("document.querySelector('[data-test=\"list-filters\"] [data-test=\"more-filters\"]')?.textContent.trim().startsWith('More filters')"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"list-filters\"] [data-test=\"filter-tag\"]') === null"))->toBeTrue();
});

it('applies a category the moment it is chosen, names it on its button, and clears it with Clear filters (#120)', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[data-test="filter-category"] [data-slot="combobox-trigger"]')
        ->click('[data-slot="combobox-item"] >> text="Roads"');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('category') === '{$this->roads->id}' && document.querySelector('[data-test=\"clear-filters\"]') !== null");

    expect(($this->buttons)($page))->toBe('Author: All authors|Category: Roads|Date: All dates')
        ->and(($this->titles)($page))->toBe('Market day');

    $page->click('[data-test="clear-filters"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('category') === null && document.querySelector('[data-test=\"clear-filters\"]') === null");

    expect(($this->titles)($page))->toBe('Ferry fares|Market day|Harbour news');
});

it('applies an author at once, keeping the category in force', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate("/admin/posts?category={$this->transport->id}")
        ->click('[data-test="filter-author"] [data-slot="combobox-trigger"]')
        ->click('[data-slot="combobox-item"] >> text="Eve Editor"');

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

    expect(($this->buttons)($page))->toBe('Author: All authors|Category: All categories|Date: October 2026')
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

    expect(($this->buttons)($page))->toBe('Author: All authors|Category: All categories|Date: 1–30 Sep 2026')
        ->and(($this->titles)($page))->toBe('Harbour news');
});

it('keeps Tag under More filters until it is applied, then shows it on the row', function (): void {
    $harbour = taxonomyTerm('tag', 'Harbour');

    $page = cmSignIn($this->editor)->navigate("/admin/posts?tag={$harbour->id}");

    cmWaitUntil($page, "document.querySelector('[data-test=\"list-filters\"] [data-test=\"filter-tag\"]') !== null");

    expect(($this->buttons)($page))->toBe('Author: All authors|Category: All categories|Date: All dates|Tag: Harbour')
        ->and($page->script("document.querySelector('[data-test=\"list-filters\"] [data-test=\"more-filters\"]') === null"))->toBeTrue();
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

it('opens Columns as a popover under its button, not a modal, its first field taking focus, and Escape closes it, back on the button (I-3135)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"columns\"]') !== null");
    $width = $page->script('document.documentElement.clientWidth');
    $page->click('[data-test="columns"]');

    // The popover is portalled: it is found by its own hook, never inside the button's parent.
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') !== null && document.querySelector('[data-test=\"column-chooser\"]').contains(document.activeElement)")
        ->assertScript("(() => { const panel = document.querySelector('[data-test=\"column-chooser\"]'); return panel.getAttribute('aria-modal') === null && getComputedStyle(document.body).pointerEvents !== 'none' && panel.getAttribute('aria-label') === 'Columns'; })()")
        ->assertScript('document.activeElement.id', 'column-author')
        ->assertAttribute('[data-test="columns"]', 'aria-expanded', 'true')
        ->assertAttribute('[data-test="columns"]', 'data-state', 'open')
        ->assertScript("(() => { const panel = document.querySelector('[data-test=\"column-chooser\"]').getBoundingClientRect(); return panel.top >= document.querySelector('[data-test=\"columns\"]').getBoundingClientRect().bottom && panel.right <= window.innerWidth; })()")
        ->assertScript("[...document.querySelectorAll('[data-test=\"column-chooser\"] [role=\"checkbox\"][id^=\"column-\"]')].map((box) => box.id).join('|')", 'column-title|column-author|column-taxonomy-category|column-date|column-taxonomy-tag|column-template|column-comments|column-thumbnails')
        ->assertScript('document.documentElement.clientWidth', $width)
        ->keys('#column-author', 'Escape');

    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') === null && document.activeElement === document.querySelector('[data-test=\"columns\"]')");
});

it('keeps focus on a column\'s move button when the keyboard moves it to the first or last place', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"columns\"]') !== null");
    $page->click('[data-test="columns"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]')?.getAnimations().length === 0 && document.querySelector('[data-test=\"column-chooser\"]').contains(document.activeElement)");

    $earlier = '[data-chooser-column="taxonomy-category"] [aria-label$=" earlier"]';
    $page->script("document.querySelector('{$earlier}').focus()");
    $page->keys($earlier, 'Enter');

    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"] [data-chooser-column]').dataset.chooserColumn === 'taxonomy-category'")
        ->assertScript("document.activeElement === document.querySelector('{$earlier}') && document.querySelector('[data-test=\"column-chooser\"]').contains(document.activeElement)", true)
        ->assertAttribute($earlier, 'aria-disabled', 'true');

    // Pressed again at the first place, it does nothing and focus stays.
    $page->keys($earlier, 'Enter');
    $page->assertScript("document.querySelector('[data-test=\"column-chooser\"] [data-chooser-column]').dataset.chooserColumn === 'taxonomy-category' && document.activeElement === document.querySelector('{$earlier}')", true);

    $later = '[data-chooser-column="comments"] [aria-label$=" later"]';
    $page->script("document.querySelector('{$later}').focus()");
    $page->keys($later, 'Enter');

    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"column-chooser\"] [data-chooser-column]')].at(-1).dataset.chooserColumn === 'comments'")
        ->assertScript("document.activeElement === document.querySelector('{$later}')", true);
});

it('shows each title\'s thumbnail by default, a small thumbnail or a mark of the same size, until Columns turns Thumbnails off', function (): void {
    $image = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg');
    docSave($this->ferry, $this->editor, docWith(docText('Fares rise in November.')), [
        BuiltInFields::FEATURED_IMAGE => ['id' => $image->id, 'alt' => '', 'decorative' => false],
    ]);

    $page = cmSignIn($this->editor)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"row-title\"]').length === 3 && document.querySelectorAll('[data-test=\"featured-thumb\"]').length === 1 && document.querySelectorAll('[data-test=\"featured-none\"]').length === 2")
        ->assertScript("(() => { const thumb = document.querySelector('[data-test=\"featured-thumb\"]'); const box = thumb.getBoundingClientRect(); const none = document.querySelector('[data-test=\"featured-none\"]').getBoundingClientRect(); return thumb.getAttribute('alt') === '' && thumb.getAttribute('loading') === 'lazy' && Math.round(box.width) === 48 && Math.round(box.height) === 32 && Math.round(none.width) === 48 && Math.round(none.height) === 32; })()")
        ->assertScript("[...document.querySelectorAll('tbody tr')].every((row) => row.children[1].querySelector('[data-test^=\"featured-\"]') !== null)")
        ->assertScript("document.querySelector('[data-test=\"featured-none\"]').textContent.trim()", 'No featured image');

    $page->click('[data-test="columns"]')
        ->click('#column-thumbnails')
        ->click('[data-test="column-chooser"] >> text="Apply"');

    cmWaitUntil($page, "document.querySelector('[data-test=\"featured-thumb\"], [data-test=\"featured-none\"]') === null");

    expect(PreferencesFor::user($this->editor->refresh())['lists.post.columns'])->not->toContain('featured');
});

it('closes an open filter when another opens or the pointer presses outside, so only one is ever open', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/posts')->click('[data-test="filter-author"] [data-slot="combobox-trigger"]');

    // Each filter's list is the one popover open, named by the search field inside it.
    $open = "[...document.querySelectorAll('[data-slot=\"popover-content\"] input')].map((field) => field.getAttribute('placeholder')).join('|')";
    cmWaitUntil($page, "{$open} === 'Search author'");
    $page->click('[data-test="filter-category"] [data-slot="combobox-trigger"]');
    cmWaitUntil($page, "{$open} === 'Search category'");
    $page->click('[data-test="list-search"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]') === null");
});

it('folds the filters into Filters (n) on a phone, each filter there opening its own list, with no sideways scroll on the page', function (): void {
    $page = cmSignIn($this->editor)->resize(390, 844)->navigate('/admin/posts?from=2026-09-01&to=2026-10-31');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-sheet\"]') !== null")
        ->assertScript("document.querySelector('[data-test=\"filters-sheet\"]').textContent.trim()", 'Filters (1)')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("document.querySelector('[data-test=\"add-new\"]').textContent.trim().startsWith('New')")
        ->click('[data-test="filters-sheet"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-panel\"] [data-test=\"filter-date\"]') !== null && document.querySelector('[data-test=\"filters-panel\"]').getAnimations().length === 0");
    $page->click('[data-test="filters-panel"] [data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"] [data-test=\"date-month\"]') !== null");
    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"]').closest('[data-slot=\"sheet-content\"]').getAnimations().length === 0");

    $page->click('[data-test="filter-date-panel"] [data-month="2026-10"]');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('month') === '2026-10' && new URLSearchParams(window.location.search).get('from') === null");
});

it('passes axe with the Date list open and with Columns open, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)->navigate('/admin/posts')->click('[data-test="filter-date"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filter-date-panel\"] [data-test=\"date-month\"]') !== null");
    // The popover fades in; axe must read its colours once the fade is over.
    shellSettled($page, '[data-slot="popover-content"]');
    $page->assertNoAccessibilityIssues(3);
    $date = cmSmallTargets($page);

    $page->keys('[data-test="date-all"]', 'Escape')->click('[data-test="columns"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') !== null && document.querySelector('[data-test=\"filter-date-panel\"]') === null");
    shellSettled($page, '[data-slot="popover-content"]');
    $page->assertNoAccessibilityIssues(3);
    $screen = cmSmallTargets($page);

    expect($date['small'])->toBe([])
        ->and($date['checked'])->toBeGreaterThan(0)
        ->and($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
