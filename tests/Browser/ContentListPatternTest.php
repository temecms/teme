<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// The content list on the list pattern (docs/architecture/design-system.md,
// section 4.26): the row line always shown, the menu, the numbered pager,
// the column chooser, the filters and the bulk bar (I-3398, I-3399, I-3421).

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
    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    lscrPrefer($this->editor, ['lists.per_page' => 25]);

    $world = taxonomyTerm('category', 'World');
    $sport = taxonomyTerm('category', 'Sport');
    $this->tags = array_map(fn (string $name): string => taxonomyTerm('tag', $name)->id, ['Elections', 'Harbours', 'Markets', 'Rain', 'Trains']);

    // 30 posts, newest last: the first 25 by date are 6 to 30.
    foreach (range(1, 30) as $n) {
        $this->travel(1)->minutes();
        $title = $n === 29
            ? 'A long title for a post that runs on past the width of its column and onto a second line, because it goes on and on about the harbour, the market, the rain and the trains'
            : "Post {$n}";
        $terms = match ($n) {
            30 => [$world->id, ...$this->tags],
            28 => [$sport->id, $this->tags[0]],
            default => [],
        };
        $author = $n % 2 === 0 ? $this->editor : $this->author;
        $post = typesDraft($author, 'post', $title, $terms);

        if ($n === 27) {
            listsPublish($post, $this->editor);
        }

        if ($n === 25) {
            schedSubmit($post, $author);
        }
    }

    $this->fontsIn = "document.body.offsetHeight > 0 && document.fonts.status === 'loaded' && [...document.fonts].every((face) => face.status !== 'loading')";
    $this->drawn = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[aria-label=\"Actions for Post 30\"]') !== null && {$this->fontsIn}");
    $this->open = fn (mixed $page, string $title): mixed => $page->click("[aria-label=\"More actions for {$title}\"]");
    $this->menu = fn (mixed $page): array => cmScriptArray($page, "[...document.querySelectorAll('[role=\"menu\"] [role=\"menuitem\"], [role=\"menu\"] [role=\"separator\"]')].map((item) => item.getAttribute('role') === 'separator' ? '|' : item.textContent.trim())", 'ContentListPatternTest');

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('shows Edit, Quick edit and View under every title without hovering', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $lines = cmScriptArray($page, "[...document.querySelectorAll('[data-test=\"row-line\"]')].map((line) => ({ opacity: getComputedStyle(line).opacity, shown: line.checkVisibility(), actions: [...line.querySelectorAll('[data-action]')].map((action) => action.dataset.action).join('|'), label: line.getAttribute('aria-label') }))", 'ContentListPatternTest');
    $byTitle = collect($lines)->keyBy('label');

    expect($lines)->toHaveCount(25)
        ->and(collect($lines)->every(fn (array $line): bool => $line['opacity'] === '1' && $line['shown'] === true))->toBeTrue()
        ->and($byTitle['Actions for Post 27']['actions'])->toBe('edit|quick-edit|view')
        ->and($byTitle['Actions for Post 30']['actions'])->toBe('edit|quick-edit|preview');
});

it('puts the state action, then Trash, in the row\'s menu, and answers Publish with Published and Undo', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    ($this->open)($page, 'Post 30');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null");

    expect(($this->menu)($page))->toBe(['Publish', '|', 'Trash'])
        ->and($page->script("document.querySelector('[role=\"menu\"] [data-action=\"trash\"]').getAttribute('data-variant')"))->toBe('destructive');

    $page->click('[role="menu"] [data-action="publish"]');
    cmWaitUntil($page, "document.querySelector('[data-sonner-toast]')?.textContent.includes('Undo') === true");

    $page->click('[data-sonner-toast] button:has-text("Undo")');
    cmWaitUntil($page, "document.querySelector('tr:has([aria-label=\"Actions for Post 30\"]) [data-slot=\"status-pill\"]')?.textContent.trim() === 'Draft'");
});

it('offers Approve, not Publish, on an item in review to someone who may decide', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    ($this->open)($page, 'Post 25');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null");

    $menu = ($this->menu)($page);

    expect($menu)->toContain('Approve')
        ->and($menu)->not->toContain('Publish');
});

it('shows Restore on the line and Delete permanently in the menu in the Trash view, and asks first', function (): void {
    lifecycleTrashedDaysAgo($this->editor, 'Old news', 1);

    $page = cmWaitUntil(cmSignIn($this->editor)->navigate('/admin/posts?tab=trash'), "document.querySelector('[aria-label=\"Actions for Old news\"]') !== null");

    expect($page->script("[...document.querySelectorAll('[aria-label=\"Actions for Old news\"] [data-action]')].map((action) => action.dataset.action).join('|')"))->toBe('restore');

    ($this->open)($page, 'Old news');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null");
    $page->click('[role="menu"] [data-action="delete"]');
    cmWaitUntil($page, "document.querySelector('[role=\"alertdialog\"], [role=\"dialog\"]') !== null");

    expect($page->script("[...document.querySelectorAll('[role=\"alertdialog\"] button, [role=\"dialog\"] button')].find((button) => button.textContent.trim() === 'Delete permanently')?.classList.contains('bg-danger') ?? false"))->toBeTrue();
});

it('draws rows 72 pixels tall for a one-line title and 92 for two, and wraps no other column', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));
    cmWaitUntil($page, $this->fontsIn);

    $rows = cmScriptArray($page, <<<'JS'
        (() => {
            const row = (title) => document.querySelector(`[aria-label="Actions for ${title}"]`).closest('tr');
            const one = row('Post 30');
            const two = row('A long title for a post that runs on past the width of its column and onto a second line, because it goes on and on about the harbour, the market, the rain and the trains');
            const others = [...document.querySelectorAll('tbody td')].filter((cell) => !cell.querySelector('[data-test="row-line"]'));

            return {
                one: one.getBoundingClientRect().height,
                two: two.getBoundingClientRect().height,
                wrapped: others.filter((cell) => cell.scrollHeight > cell.clientHeight + 1 || [...cell.querySelectorAll('*')].some((part) => part.getClientRects().length > 1)).length,
            };
        })()
        JS, 'ContentListPatternTest');

    expect(abs($rows['one'] - 72))->toBeLessThanOrEqual(2, json_encode($rows))
        ->and(abs($rows['two'] - 92))->toBeLessThanOrEqual(2, json_encode($rows))
        ->and($rows['wrapped'])->toBe(0);
});

it('keeps the tick box and title pinned and brings the date fully into view beside the menu when scrolled to the end', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1024, 900)->navigate('/admin/posts'));

    cmWaitUntil($page, "document.querySelector('[data-slot=\"table-frame\"] [data-slot=\"scroll-area\"]') !== null");

    $start = cmScriptArray($page, "(() => { const viewport = document.querySelector('[data-slot=\"table-frame\"] [data-slot=\"scroll-area\"]'); return { title: document.querySelector('tr:has([aria-label=\"Actions for Post 30\"]) [data-test=\"row-title\"]').getBoundingClientRect().left, wide: viewport.scrollWidth > viewport.clientWidth }; })()", 'ContentListPatternTest');
    $before = $start['title'];
    $wide = $start['wide'];

    $page->assertSee('More columns: scroll sideways');
    $page->script("(() => { const viewport = document.querySelector('[data-slot=\"table-frame\"] [data-slot=\"scroll-area\"]'); viewport.scrollLeft = viewport.scrollWidth; })()");
    cmWaitUntil($page, "(() => { const viewport = document.querySelector('[data-slot=\"table-frame\"] [data-slot=\"scroll-area\"]'); return viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 1; })()");

    $after = cmScriptArray($page, <<<'JS'
        (() => {
            const row = document.querySelector('[aria-label="Actions for Post 30"]').closest('tr');
            const cells = [...row.children];
            const date = cells[cells.length - 2].getBoundingClientRect();
            const menu = cells[cells.length - 1].getBoundingClientRect();

            return { title: row.querySelector('[data-test="row-title"]').getBoundingClientRect().left, dateRight: date.right, menuLeft: menu.left };
        })()
        JS, 'ContentListPatternTest');

    expect($wide)->toBeTrue()
        ->and(abs($after['title'] - $before))->toBeLessThanOrEqual(1)
        ->and($after['dateRight'])->toBeLessThanOrEqual($after['menuLeft'] + 1);
});

it('shows two term chips or one, then +N opening the rest', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));

    $chips = cmScriptArray($page, "(() => { const cell = [...document.querySelector('[aria-label=\"Actions for Post 30\"]').closest('tr').querySelectorAll('[data-slot=\"term-chips\"]')].find((chips) => chips.querySelector('[data-test=\"term-chips-more\"]')); const more = cell.querySelector('[data-test=\"term-chips-more\"]'); return { shown: [...cell.children].filter((child) => child.dataset.slot === 'term-chip').length, label: more.getAttribute('aria-label'), text: more.textContent.trim() }; })()", 'ContentListPatternTest');

    expect($chips['shown'])->toBeIn([1, 2])
        ->and($chips['text'])->toBe('+'.(5 - $chips['shown']))
        ->and($chips['label'])->toStartWith((5 - $chips['shown']).' more tags: ');

    $page->keys('tr:has([aria-label="Actions for Post 30"]) [data-test="term-chips-more"]', 'Enter');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]')?.textContent.includes('Trains') === true");
});

it('pages by number, keeps the filters, and reads 1 to 25 of 30', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->assertSee('1 to 25 of 30');
    $page->click('[aria-label="Pages"] [aria-label="Page 2"]');
    cmWaitUntil($page, "new URLSearchParams(location.search).get('page') === '2' && document.querySelector('[aria-label=\"Pages\"] [aria-current=\"page\"]')?.textContent.trim() === '2'");
    $page->assertSee('26 to 30 of 30');

    // Another 15 of the author's, so their posts fill two pages.
    foreach (range(1, 15) as $n) {
        typesDraft($this->author, 'post', "More {$n}");
    }

    $page->navigate('/admin/posts?author='.$this->author->id);
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Pages\"] [aria-label=\"Page 2\"]') !== null");

    expect($page->script("new URL(document.querySelector('[aria-label=\"Pages\"] [aria-label=\"Page 2\"]').href).searchParams.get('author')"))->toBe((string) $this->author->id);
});

it('reorders and hides columns in the chooser and keeps the order after a reload', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));
    $headings = fn (): array => cmScriptArray($page, "[...document.querySelectorAll('thead th')].map((cell) => cell.textContent.trim()).filter((text) => text !== '')", 'ContentListPatternTest');

    $page->click('[data-test="columns"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') !== null");

    // Date is last: three moves bring it before Author's place one by one.
    foreach (range(1, 5) as $step) {
        if ($page->script("(() => { const names = [...document.querySelectorAll('[data-test=\"column-chooser\"] [aria-label^=\"Move \"][aria-label$=\" earlier\"]')].map((button) => button.getAttribute('aria-label')); return names.indexOf('Move Date earlier') <= names.indexOf('Move Author earlier') || names.indexOf('Move Author earlier') === -1; })()")) {
            break;
        }

        $page->click('[aria-label="Move Date earlier"]');
    }

    $page->click('[data-test="column-chooser"] label:has-text("Tags")');
    $page->click('[data-test="column-chooser"] button:has-text("Apply")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') === null && [...document.querySelectorAll('thead th')].every((cell) => cell.textContent.trim() !== 'Tags')");

    $shown = $headings();

    expect(array_search('Date', $shown, true))->toBeLessThan(array_search('Author', $shown, true))
        ->and($shown)->not->toContain('Tags');

    $page->navigate('/admin/posts');
    ($this->drawn)($page);

    expect($headings())->toBe($shown);
});

it('ticks three rows, shows 3 posts selected, offers Select all matching on a full page, and clears', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    foreach (['Post 30', 'Post 28', 'Post 27'] as $title) {
        $page->click("[aria-label=\"Select {$title}\"]");
    }

    cmWaitUntil($page, "document.querySelector('[role=\"region\"][aria-label=\"Bulk actions\"] [aria-live]')?.textContent.trim() === '3 posts selected'");

    $page->click('[aria-label="Select all on this page"]');
    cmWaitUntil($page, "[...document.querySelectorAll('[role=\"region\"][aria-label=\"Bulk actions\"] button')].some((button) => button.textContent.trim() === 'Select all 30 posts')");

    $page->click('[role="region"][aria-label="Bulk actions"] button:has-text("Clear selection")');
    cmWaitUntil($page, "document.querySelector('[role=\"region\"][aria-label=\"Bulk actions\"]') === null && document.querySelectorAll('tbody [role=\"checkbox\"][aria-checked=\"true\"]').length === 0");
});

it('filters by author and by a tag through the Combobox by keyboard alone', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));

    $page->keys('[data-test="filter-author"] [data-slot="combobox-trigger"]', 'Enter');
    cmWaitUntil($page, "document.activeElement?.getAttribute('placeholder') === 'Search author'");
    $page->keys(':focus', ['A', 'd', 'ArrowDown', 'Enter']);
    cmWaitUntil($page, "new URLSearchParams(location.search).get('author') === '{$this->author->id}'");

    ($this->drawn)($page->navigate('/admin/posts'));
    $page->keys('[data-test="filter-tag"] [data-slot="combobox-trigger"]', 'Enter');
    cmWaitUntil($page, "document.activeElement?.getAttribute('placeholder') === 'Search tag'");
    $page->keys(':focus', ['R', 'a']);
    cmWaitUntil($page, "[...document.querySelectorAll('[role=\"option\"]')].some((option) => option.textContent.includes('Rain')) && !document.body.textContent.includes('Searching…')");
    $page->keys(':focus', ['ArrowDown', 'Enter']);
    cmWaitUntil($page, "new URLSearchParams(location.search).get('tags') !== null || new URLSearchParams(location.search).get('tag') !== null");
});

it('does every row action, the bulk bar and the filters with the keyboard alone', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));

    $order = cmScriptArray($page, <<<'JS'
        (() => {
            const row = document.querySelector('[aria-label="Actions for Post 24"]').closest('tr');
            const focusable = [...row.querySelectorAll('a[href], button, input, [role="checkbox"], [tabindex="0"]')].filter((element) => element.tabIndex >= 0 && element.checkVisibility());

            return focusable.map((element) => element.dataset.action ?? element.getAttribute('role') ?? element.dataset.test ?? element.tagName.toLowerCase());
        })()
        JS, 'ContentListPatternTest');

    expect($order)->toBe(['checkbox', 'row-title', 'edit', 'quick-edit', 'preview', 'row-menu'], json_encode($order));

    $page->keys('[aria-label="Select Post 24"]', 'Space');
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Select Post 24\"]').getAttribute('aria-checked') === 'true'");

    $page->keys('[aria-label="Actions for Post 24"] [data-action="quick-edit"]', 'Enter');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') !== null");
    $page->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null");
    cmWaitUntil($page, "document.activeElement?.dataset.action === 'quick-edit' && document.activeElement.closest('[data-test=\"row-line\"]')?.getAttribute('aria-label') === 'Actions for Post 24'");
});

it('passes axe with the chooser, a row menu and a filter open, every target 24 pixels or more, at 1280 in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));
    $page->assertNoAccessibilityIssues(3);
    $list = cmSmallTargets($page);

    $page->click('[data-test="columns"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]')?.getAnimations().length === 0");
    $page->assertNoAccessibilityIssues(3);
    $chooser = cmSmallTargets($page);
    $page->keys('[data-test="column-chooser"]', 'Escape');
    cmWaitUntil($page, "document.querySelector('[data-test=\"column-chooser\"]') === null");

    ($this->open)($page, 'Post 30');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0");
    $page->assertNoAccessibilityIssues(3);
    $menu = cmSmallTargets($page);
    $page->keys('[role="menu"]', 'Escape');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null");

    $page->click('[data-test="filter-author"] [data-slot="combobox-trigger"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]')?.getAnimations().length === 0");
    $page->assertNoAccessibilityIssues(3);
    $filter = cmSmallTargets($page);

    foreach ([$list, $chooser, $menu, $filter] as $targets) {
        expect($targets['small'])->toBe([])
            ->and($targets['checked'])->toBeGreaterThan(0);
    }
})->with(['light', 'dark']);

it('opens a row menu inside the main landmark, drawn by the shared menu (#286)', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->resize(1280, 900)->navigate('/admin/posts'));

    ($this->open)($page, 'Post 30');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0");

    $page->assertScript("document.querySelector('main').contains(document.querySelector('[role=\"menu\"]'))", true)
        ->assertScript("document.querySelector('[role=\"menu\"]').dataset.slot", 'dropdown-menu-content')
        ->assertScript("document.querySelector('[role=\"menu\"] [data-action]') !== null", true);
});

it('lists posts as cards on a phone with status, date and author, and no column chooser', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 812)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelectorAll('[data-slot=\"list-card\"]').length === 25")
        ->assertScript("document.querySelector('[data-slot=\"list-cards\"]').dataset.cardFacts", 'status date author')
        ->assertScript("document.querySelector('[data-test=\"columns\"]') === null && document.querySelector('[data-test=\"column-chooser\"]') === null", true)
        ->assertScript("document.querySelector('table') === null", true);

    $facts = cmScriptArray($page, "[...document.querySelectorAll('[data-slot=\"list-card\"] [data-card=\"facts\"]')].map((line) => ({ order: [...line.querySelectorAll('[data-fact]')].map((fact) => fact.dataset.fact).join(' '), text: line.innerText.trim() }))", 'ContentListPatternTest');

    expect($facts)->toHaveCount(25);

    foreach ($facts as $line) {
        expect($line['order'])->toBe('status date author')
            ->and($line['text'])->not->toStartWith('·')
            ->and($line['text'])->not->toEndWith('·');
    }
});

it('sorts the cards with the Sort control and reverses the order', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 812)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"cards-bar\"]') !== null")
        ->click('[data-slot="cards-bar"] [aria-label="Sort"]');

    // The list opens with a fade; a press before it settles is not taken as a choice.
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') !== null && document.getAnimations().length === 0")
        ->click('[role="option"] >> text="Title"');

    cmWaitUntil($page, "new URLSearchParams(location.search).get('sort') === 'title'");

    $page->click('[data-slot="cards-bar"] [aria-label="Reverse the order"]');

    cmWaitUntil($page, "new URLSearchParams(location.search).get('sort') === 'title' && new URLSearchParams(location.search).get('direction') === 'desc'");
});

it('folds the filters into Filters (n) on a phone and applies one from the sheet', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 812)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-sheet\"]') !== null")
        ->assertScript("document.querySelector('[data-test=\"filters-sheet\"]').textContent.trim()", 'Filters (0)')
        ->click('[data-test="filters-sheet"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-panel\"]')?.getAnimations().length === 0")
        ->click('[data-test="filters-panel"] [data-test="filter-author"] [data-slot="combobox-trigger"]')
        ->click('[data-slot="combobox-item"] >> text="Ada Author"');

    cmWaitUntil($page, "new URLSearchParams(location.search).get('author') === '{$this->author->id}' && document.querySelector('[data-test=\"filters-sheet\"]')?.textContent.trim() === 'Filters (1)'");
});

it('pins the bulk bar to the foot when a card is ticked', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 812)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"list-card\"] [role=\"checkbox\"]') !== null")
        ->click('[aria-label="Select Post 30"]');

    cmWaitUntil($page, "document.querySelector('[role=\"region\"][aria-label=\"Bulk actions\"]') !== null")
        ->assertScript("Math.abs(document.querySelector('[role=\"region\"][aria-label=\"Bulk actions\"]').getBoundingClientRect().bottom - window.innerHeight) <= 24", true);
});

it('reads Page 1 of 2 on a phone', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 812)->navigate('/admin/posts');

    cmWaitUntil($page, "document.querySelector('[aria-label=\"Pages\"]') !== null")
        ->assertScript("document.querySelector('[aria-label=\"Pages\"]').innerText.includes('Page 1 of 2')", true);
});

it('reaches everything at 320 pixels with nothing clipped and no sideways page scroll', function (): void {
    cmItem($this->editor, 'Harbour news');
    $page = cmSignIn($this->editor)->resize(320, 700);

    foreach (['/admin/posts', '/admin/tags', '/admin/comments'] as $path) {
        $page->navigate($path);
        cmWaitUntil($page, "document.querySelector('h1') !== null && {$this->fontsIn}")
            ->assertScript('document.scrollingElement.scrollWidth <= window.innerWidth', true)
            // Nothing clipped: the list's frame, views and filters end inside the screen.
            ->assertScript("[...document.querySelectorAll('[data-slot=\"table-frame\"], [data-slot=\"in-page-nav\"], [data-test=\"list-filters\"]')].every((part) => part.getBoundingClientRect().right <= window.innerWidth)", true);
    }

    $page->navigate('/admin/posts');
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Select Post 30\"]') !== null");

    // From the first card's tick box, Tab reaches the title, every action on its line, then its menu.
    $reached = [];
    $page->click('[aria-label="Select Post 30"]');

    foreach (range(1, 5) as $step) {
        $page->keys(':focus', 'Tab');
        $reached[] = $page->script("document.activeElement?.dataset.action ?? document.activeElement?.dataset.test ?? ''");
    }

    expect($reached)->toBe(['row-title', 'edit', 'quick-edit', 'preview', 'row-menu']);
});

it('passes axe on the cards, the filters sheet and a card\'s menu, every target 44 pixels or more on touch, in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = visit('/admin/login')
        ->on()->mobile()
        ->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/posts');

    // Every control a thumb presses, inside the given place, smaller than 44 pixels either way, a tick box by the label around it; a title's text link is a line of words, not a control.
    $small = fn (string $within): array => cmScriptArray($page, "[...document.querySelectorAll('{$within}')].flatMap((place) => [...place.querySelectorAll('button, [role=\"checkbox\"], [role=\"menuitem\"], [role=\"combobox\"], a:not([data-test=\"row-title\"]), input')]).filter((control) => control.checkVisibility() && control.closest('[aria-hidden=\"true\"]') === null).filter((control) => { const box = (control.matches('[role=\"checkbox\"]') ? (control.closest('label') ?? control) : control).getBoundingClientRect(); return Math.min(box.width, box.height) < 44; }).map((control) => (control.dataset.action ?? control.dataset.test ?? control.getAttribute('aria-label') ?? control.textContent.trim()).slice(0, 40))", 'ContentListPatternTest');

    cmWaitUntil($page, "document.querySelectorAll('[data-slot=\"list-card\"]').length === 25 && {$this->fontsIn}");
    $page->assertNoAccessibilityIssues(3);
    $cards = $small('[data-slot="list-card"], [data-slot="cards-bar"]');

    $page->click('[data-test="filters-sheet"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-panel\"]')?.getAnimations().length === 0 && [...document.querySelectorAll('[data-slot=\"sheet-overlay\"]')].every((overlay) => overlay.getAnimations().length === 0)");
    $page->assertNoAccessibilityIssues(3);
    $sheet = $small('[data-test="filters-panel"]');
    $page->keys('[data-test="filters-panel"]', 'Escape');
    cmWaitUntil($page, "document.querySelector('[data-test=\"filters-panel\"]') === null && document.querySelector('[data-slot=\"sheet-overlay\"]') === null");

    // By its key: the shell's bar at the top of a phone's screen sits over the first card's button once it is scrolled to.
    $page->keys('[aria-label="More actions for Post 30"]', 'Enter');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0");
    $page->assertNoAccessibilityIssues(3);
    $menu = $small('[role="menu"]');

    expect($cards)->toBe([])
        ->and($sheet)->toBe([])
        ->and($menu)->toBe([]);
})->with(['light', 'dark']);
