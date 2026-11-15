<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
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
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->transport = taxonomyTerm('category', 'Transport');
    $this->roads = taxonomyTerm('category', 'Roads', $this->transport->id);

    // Live, in two categories side by side; a draft; one in review; two in the trash.
    $this->harbour = typesDraft($this->editor, 'post', 'Harbour news', [$this->transport->id, $this->roads->id]);
    listsPublish($this->harbour, $this->editor);
    $this->market = typesDraft($this->editor, 'post', 'Market day', [$this->roads->id]);
    $this->ferry = lifecycleDraft($this->editor, 'Ferry fares');
    schedSubmit($this->ferry, $this->editor);
    $this->old = lifecycleTrashedDaysAgo($this->editor, 'Old notice', 2);
    $this->lost = lifecycleTrashedDaysAgo($this->editor, 'Lost cat', 1);

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('writes each row\'s state on a pill in its Status column, and shows every tab with its count, Scheduled at zero', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->assertScript("[...document.querySelectorAll('tbody [data-slot=\"status-pill\"]')].map((element) => element.textContent.trim()).sort().join('|')", 'Draft|In review|Published')
        // The first five tabs show; the rest are under More.
        ->assertScript("[...document.querySelectorAll('nav[aria-label=\"Status\"] a')].map((tab) => tab.textContent.trim()).join('|')", 'All3|Mine3|Published1|Private0|Drafts1')
        ->click('nav[aria-label="Status"] button:has-text("More")');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0")
        ->assertScript("[...document.querySelectorAll('[role=\"menu\"] a')].map((tab) => tab.textContent.trim()).join('|')", 'In review1|Scheduled0|Trash2');
});

it('opens the latest saved version from a row\'s Preview, in a new tab', function (): void {
    docSave($this->market, $this->editor, docWith(docText('Stalls open at nine.')));
    $preview = route('content.preview', ['content' => $this->market->id], false);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->assertAttribute('[aria-label="Actions for Market day"] [data-action="preview"]', 'href', $preview)
        ->assertScript("document.querySelector('[aria-label=\"Actions for Market day\"] [data-action=\"preview\"]').textContent.trim()", 'Preview: Market day, opens in a new tab');

    $page->navigate($preview)
        ->assertSee('Preview of the latest saved version')
        ->assertSee('Stalls open at nine.');
});

it('filters by a category and its children from the Category filter, and shows each row\'s categories in the column', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[data-test="filter-category"] [data-slot="combobox-trigger"]')
        ->click('[data-slot="combobox-item"] >> text="Roads"');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('category') === '{$this->roads->id}'");

    $page->assertScript("new URLSearchParams(window.location.search).get('category')", $this->roads->id)
        ->assertScript("document.querySelector('table').innerText.includes('Market day')", true)
        ->assertScript("document.querySelector('table').innerText.includes('Harbour news')", true)
        ->assertScript("document.querySelector('table').innerText.includes('Ferry fares')", false);

    $page->navigate('/admin/posts')
        ->assertScript("[...document.querySelector('[aria-label=\"Actions for Harbour news\"]').closest('tr').querySelectorAll('[data-slot=\"term-chip\"], [data-test=\"term-chips-more\"]')].map((chip) => chip.getAttribute('aria-label') ?? chip.textContent.trim()).join('|').includes('Roads') && document.querySelector('[aria-label=\"Actions for Harbour news\"]').closest('tr').innerText.includes('Transport')", true);
});

it('moves two posts to the trash from the bulk bar, and says how many', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Select Harbour news"]')
        ->click('[aria-label="Select Market day"]')
        ->assertSeeIn('[role="region"][aria-label="Bulk actions"]', '2 posts selected')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Move to trash"')
        ->assertSee('Moved 2 posts to the trash.');

    expect(Content::onlyTrashed()->whereIn('id', [$this->harbour->id, $this->market->id])->count())->toBe(2);
});

it('names every item before deleting permanently from the bar, and deletes them only once confirmed', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts?tab=trash')
        ->click('[aria-label="Select Old notice"]')
        ->click('[aria-label="Select Lost cat"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Delete permanently"');

    shellSettled($page, '[role="dialog"]')
        ->assertScript("document.querySelector('[data-test=\"confirm-items\"] h2')?.textContent", 'Delete 2 posts permanently?')
        ->assertScript("[...document.querySelectorAll('[data-test=\"confirm-items-list\"] li')].map((item) => item.textContent).sort().join('|')", 'Lost cat|Old notice');

    expect(Content::onlyTrashed()->count())->toBe(2);

    $page->click('[role="dialog"] >> text="Delete permanently"')
        ->assertSee('Deleted 2 posts permanently.');

    expect(Content::withTrashed()->whereIn('id', [$this->old->id, $this->lost->id])->count())->toBe(0);
});

it('keeps a dozen titles inside the confirmation, its list scrolling within the window', function (): void {
    foreach (range(1, 12) as $n) {
        lifecycleTrashedDaysAgo($this->editor, "Notice {$n}", 3);
    }

    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts?tab=trash')
        ->click('[aria-label="Select all on this page"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Delete permanently"');

    shellSettled($page, '[role="dialog"]')
        ->assertScript("document.querySelectorAll('[data-test=\"confirm-items-list\"] li').length", 14)
        ->assertScript("(() => { const list = document.querySelector('[data-test=\"confirm-items-list\"]'); const dialog = document.querySelector('[role=\"dialog\"]').getBoundingClientRect(); return list.scrollHeight > list.clientHeight && dialog.bottom <= window.innerHeight; })()");

    expect(Content::onlyTrashed()->count())->toBe(14);
});

it('passes axe on Posts with a row selected, and on the trash with the delete confirmation open, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Select Harbour news"]')
        ->assertSeeIn('[role="region"][aria-label="Bulk actions"]', '1 post selected')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->navigate('/admin/posts?tab=trash')
        ->click('[aria-label="Select Old notice"]')
        ->click('[aria-label="Select Lost cat"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Delete permanently"');

    // The dialog and its overlay fade in, and axe doesn't retry: it would read their colours mid-fade.
    shellSettled($page, '[role="dialog"]')
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [role=\"dialog\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);

    $dialog = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($dialog['small'])->toBe([])
        ->and($dialog['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('draws the review chip\'s remove button 24 pixels square, as the term chip\'s is, passing the target size in both schemes (issue 99)', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)->navigate('/admin/posts?review=pending');

    cmWaitUntil($page, "document.querySelector('[aria-label=\"Remove the filter: Has a pending review\"]') !== null")
        ->assertScript("(() => { const box = document.querySelector('[aria-label=\"Remove the filter: Has a pending review\"]').getBoundingClientRect(); return Math.min(box.width, box.height) >= 24; })()", true);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
