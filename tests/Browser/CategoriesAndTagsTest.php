<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Models\Term;
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

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    // Transport › Rail › Trams, Transport › Roads, and Weather; two tags.
    $this->transport = taxonomyTerm('category', 'Transport');
    $this->rail = taxonomyTerm('category', 'Rail', $this->transport->id);
    taxonomyTerm('category', 'Trams', $this->rail->id);
    $this->roads = taxonomyTerm('category', 'Roads', $this->transport->id);
    taxonomyTerm('category', 'Weather');
    $this->harbour = taxonomyTerm('tag', 'Harbour');
    taxonomyTerm('tag', 'Ferries');

    // Transport: two posts. Roads: one. Rail, Trams and Weather: none.
    typesDraft($this->editor, 'post', 'Harbour news', [$this->transport->id, $this->harbour->id]);
    typesDraft($this->editor, 'post', 'Ferry fares', [$this->transport->id]);
    typesDraft($this->editor, 'post', 'Market day', [$this->roads->id]);

    // The category rows as "depth:name", in order.
    $this->rows = "[...document.querySelectorAll('[data-test=\"term-name\"]')].map((name) => name.dataset.depth + ':' + name.textContent).join('|')";

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('terms.index', ['taxonomy' => 'categories']))->assertOk();
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('shows Categories and Tags as tabs with their sizes under one heading, and each category under its parent, a step in', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/categories')
        ->assertScript('document.querySelector("main h1")?.textContent', 'Categories and tags')
        ->assertScript("[...document.querySelectorAll('nav[aria-label=\"Kind\"] a')].map((tab) => tab.textContent.trim()).join('|')", 'Categories5|Tags2')
        ->assertScript($this->rows, '0:Transport|1:Rail|2:Trams|1:Roads|0:Weather')
        ->assertScript("document.querySelector('[data-test=\"term-name\"][data-depth=\"2\"]').parentElement.textContent", '— — Trams, under Rail');
});

it('adds a subcategory from the full form, then keeps its parent for the next and puts the cursor back in Name', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/categories')
        ->type('#term-add-name', 'Bridges')
        ->click('#term-add-parent')
        ->click('[role="option"] >> text="Roads"');

    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null")
        ->type('#term-add-description', 'Every crossing.')
        ->press('Add category');

    cmWaitUntil($page, "document.body.innerText.includes('Added “Bridges”.')")
        ->assertScript($this->rows, '0:Transport|1:Rail|2:Trams|1:Roads|2:Bridges|0:Weather')
        ->assertScript("document.querySelector('[data-test=\"term-add\"] input[name=\"parent_id\"]')?.value", $this->roads->id)
        ->assertScript('document.activeElement?.id', 'term-add-name')
        ->assertValue('#term-add-name', '');

    expect(Term::query()->where('name', 'Bridges')->sole()->only(['parent_id', 'description']))
        ->toBe(['parent_id' => $this->roads->id, 'description' => 'Every crossing.']);
});

it('opens the list a count counts, a category\'s with its subcategories\' posts, and names a tag in a chip that another filter keeps and its link removes', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate('/admin/categories')
        ->click("a[data-test=\"term-count\"][href*=\"{$this->transport->id}\"]")
        ->assertScript("new URLSearchParams(window.location.search).get('category')", $this->transport->id)
        ->assertScript("document.querySelector('table').innerText.includes('Ferry fares')", true)
        ->assertScript("document.querySelector('table').innerText.includes('Market day')", true);

    $page->navigate('/admin/tags')
        ->click("a[data-test=\"term-count\"][href*=\"{$this->harbour->id}\"]")
        ->assertScript("document.querySelector('[data-test=\"filter-chip\"]')?.textContent", 'Tag: Harbour')
        ->assertScript("document.querySelector('table').innerText.includes('Ferry fares')", false)
        ->type('[data-test="list-search"]', 'fares')
        ->keys('[data-test="list-search"]', 'Enter');

    cmWaitUntil($page, "new URLSearchParams(window.location.search).get('search') === 'fares'");

    $page->assertScript("new URLSearchParams(window.location.search).get('tab')", 'all')
        ->assertScript("new URLSearchParams(window.location.search).get('tag')", $this->harbour->id)
        ->assertScript("document.querySelector('[data-test=\"filter-chip\"]')?.textContent", 'Tag: Harbour')
        ->click('a[aria-label="Remove the filter: Tag: Harbour"]')
        ->assertScript("new URLSearchParams(window.location.search).has('tag')", false)
        ->assertScript("document.querySelector('table').innerText.includes('Ferry fares')", true);
});

it('edits a subcategory\'s name without moving it or losing its description, offering no parent inside its own branch', function (): void {
    Term::query()->whereKey($this->rail->id)->update(['description' => 'Lines and stations.']);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/categories')
        ->click('[aria-label="Actions for Rail"] >> text="Edit"');

    // The menu fades out as the sheet slides in.
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null");

    shellSettled($page, '[data-slot="sheet-content"], [data-slot="sheet-overlay"]')
        ->assertScript("document.querySelector('#term-edit-description')?.value", 'Lines and stations.')
        ->assertScript("document.querySelector('[data-slot=\"sheet-content\"] input[name=\"parent_id\"]')?.value", $this->transport->id)
        ->click('#term-edit-parent');

    cmWaitUntil($page, "document.querySelectorAll('[role=\"option\"]').length > 0")
        ->assertScript("[...document.querySelectorAll('[role=\"option\"]')].map((option) => option.textContent).join('|')", 'None|Transport|Roads|Weather')
        ->click('[role="option"] >> text="Transport"');

    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null")
        ->type('#term-edit-name', 'Railways')
        ->click('[data-slot="sheet-content"] >> text="Save"')
        ->assertScript($this->rows, '0:Transport|1:Railways|2:Trams|1:Roads|0:Weather');

    expect(Term::query()->find($this->rail->id)->only(['name', 'parent_id', 'description']))
        ->toBe(['name' => 'Railways', 'parent_id' => $this->transport->id, 'description' => 'Lines and stations.']);
});

it('says what deleting each category does, in the singular where one, and deletes only once confirmed', function (): void {
    $warning = "document.querySelector('[role=\"dialog\"] [data-slot=\"dialog-description\"]')?.textContent";
    $page = cmSignIn($this->editor);

    foreach ([
        'Roads' => '1 post loses this category. This can’t be undone.',
        'Rail' => 'Its subcategory moves up a level, to “Transport”. Nothing under it is deleted. This can’t be undone.',
        'Weather' => 'This can’t be undone.',
        'Transport' => '2 posts lose this category. Its 2 subcategories move up a level, to the top. Nothing under them is deleted. This can’t be undone.',
    ] as $name => $words) {
        $page->navigate('/admin/categories')
            ->click("[aria-label=\"Actions for {$name}\"] >> text=\"Delete\"")
            ->assertScript("document.querySelector('[role=\"dialog\"] h2')?.textContent", "Delete “{$name}”?")
            ->assertScript($warning, $words);
    }

    expect(Term::query()->whereKey($this->transport->id)->exists())->toBeTrue();

    $page->click('[role="dialog"] >> text="Delete category"')
        // The toast itself: the closing dialog's own words would match "Deleted." too.
        ->assertScript("[...document.querySelectorAll('[data-test=\"flash-toast\"]')].some((toast) => toast.textContent.includes('Deleted.'))")
        ->assertScript($this->rows, '0:Rail|1:Trams|0:Roads|0:Weather');

    expect(Term::query()->whereKey($this->transport->id)->exists())->toBeFalse();
});

it('passes axe on Categories, and with the edit sheet open, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/categories')
        ->assertScript("document.querySelectorAll('[data-test=\"term-count\"]').length", 2)
        ->assertNoAccessibilityIssues(3);

    $screen = cmSmallTargets($page);

    $page->click('[aria-label="Actions for Roads"] >> text="Edit"');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null");

    // The sheet and its overlay slide and fade in, and axe doesn't retry.
    shellSettled($page, '[data-slot="sheet-content"], [data-slot="sheet-overlay"]')
        ->assertNoAccessibilityIssues(3);

    $sheet = cmSmallTargets($page);

    expect($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0)
        ->and($sheet['small'])->toBe([])
        ->and($sheet['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
