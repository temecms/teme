<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
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
    $this->harbour = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($this->harbour, $this->editor);
    $this->market = lifecycleDraft($this->editor, 'Market day');

    // A row's line, as its actions' ids, and its More's, once open.
    $this->line = fn (mixed $page, string $title): string => $page->script("[...document.querySelectorAll('[aria-label=\"Actions for {$title}\"] [data-action]')].map((action) => action.dataset.action).join('|')");
    $this->opacity = fn (mixed $page, string $title): string => $page->script("getComputedStyle(document.querySelector('[aria-label=\"Actions for {$title}\"]')).opacity");
    // In its own fonts: a table laid out in the fallback face re-lays itself a few pixels once IBM Plex arrives (#238).
    // The read of offsetHeight lays the page out first, which is when the browser asks for the fonts it needs: before
    // that, fonts.status says "loaded" because nothing has been asked for yet, and the table is still to be measured (#266).
    $this->fontsIn = "document.body.offsetHeight > 0 && document.fonts.status === 'loaded' && [...document.fonts].every((face) => face.status !== 'loading')";
    $this->drawn = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[aria-label=\"Actions for Market day\"]') !== null && {$this->fontsIn}");

    // Where the table's columns sit, read once every font asked for has arrived. Two reads that agreed were not
    // enough: on a busy machine a font can take longer than the reads did, and arrive between the read before and
    // the read after (issue 266).
    $this->still = function (mixed $page, string $where): mixed {
        cmWaitUntil($page, $this->fontsIn);

        return cmScriptArray($page, $where, 'RowLinksTest');
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
});

it('draws a row\'s line under its title, shown without hovering, in a line that keeps its space (I-3398)', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $before = $page->script("(() => { const line = document.querySelector('[aria-label=\"Actions for Market day\"]'); const title = line.closest('td').querySelector('[data-test=\"row-title\"]'); return { row: Math.round(line.closest('tr').getBoundingClientRect().height), under: title.getBoundingClientRect().bottom <= line.getBoundingClientRect().top + 1, group: line.getAttribute('role') }; })()");

    expect(($this->opacity)($page, 'Market day'))->toBe('1')
        ->and($before['under'])->toBeTrue()
        ->and($before['group'])->toBe('group')
        ->and(($this->line)($page, 'Market day'))->toBe('edit|quick-edit|preview');

    $page->hover('tr:has([aria-label="Actions for Market day"]) td:nth-child(3)');

    expect($page->script("Math.round(document.querySelector('[aria-label=\"Actions for Market day\"]').closest('tr').getBoundingClientRect().height)"))->toBe($before['row'])
        ->and(($this->opacity)($page, 'Harbour news'))->toBe('1');
});

it('shows the links when the keyboard reaches them, Tab going from the title to Edit', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->keys('tr:has([aria-label="Actions for Market day"]) [data-test="row-title"]', 'Tab');

    cmWaitUntil($page, "document.activeElement?.dataset.action === 'edit' && document.activeElement.closest('[role=\"group\"]')?.getAttribute('aria-label') === 'Actions for Market day' && getComputedStyle(document.querySelector('[aria-label=\"Actions for Market day\"]')).opacity === '1'");
});

it('offers View at a live post\'s address and Preview for a draft, each in a new tab', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->assertAttribute('[aria-label="Actions for Harbour news"] [data-action="view"]', 'href', (string) Addresses::relativeUrl($this->harbour->refresh()))
        ->assertAttribute('[aria-label="Actions for Harbour news"] [data-action="view"]', 'target', '_blank')
        ->assertAttribute('[aria-label="Actions for Market day"] [data-action="preview"]', 'href', route('content.preview', ['content' => $this->market->id], false))
        ->assertAttribute('[aria-label="Actions for Market day"] [data-action="preview"]', 'target', '_blank')
        ->assertScript("document.querySelector('[aria-label=\"Actions for Harbour news\"] [data-action=\"view\"]').textContent.trim()", 'View: Harbour news, opens in a new tab');
});

it('keeps Publish, then Trash, under More, a menu that opens below its button without moving the page or leaving it', function (): void {
    $page = ($this->drawn)(cmNavigate(cmSignIn($this->editor), '/admin/posts'));
    $where = "(() => ({ width: document.documentElement.clientWidth, address: location.pathname + location.search, columns: [...document.querySelectorAll('thead th')].map((cell) => Math.round(cell.getBoundingClientRect().width)).join(',') }))()";
    $before = ($this->still)($page, $where);

    $page->click('[aria-label="More actions for Market day"]');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null && document.querySelector('[role=\"menu\"]').getAnimations().length === 0")
        ->assertScript("[...document.querySelectorAll('[role=\"menu\"] [role=\"menuitem\"]')].map((item) => item.textContent.trim()).join('|')", 'Publish|Trash')
        ->assertScript("document.querySelector('[role=\"menu\"]').getBoundingClientRect().top >= document.querySelector('[aria-label=\"More actions for Market day\"]').getBoundingClientRect().bottom");

    expect(($this->still)($page, $where))->toBe($before);

    $page->keys('[role="menu"]', 'Escape');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null && document.activeElement === document.querySelector('[aria-label=\"More actions for Market day\"]')");
});

it('keeps the line in view while its More is open from the keyboard, though the menu has taken the focus out of the row', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->keys('[aria-label="More actions for Market day"]', 'Enter');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null && document.activeElement?.closest('[role=\"menu\"]') !== null && document.querySelector('[role=\"menu\"]').getAnimations().length === 0")
        ->assertScript("document.querySelector('tr:has([aria-label=\"Actions for Market day\"])').contains(document.activeElement)", false);

    expect(($this->opacity)($page, 'Market day'))->toBe('1');
});

it('keeps the page still while a row\'s confirmation is open, the scrollbar\'s room kept (#173)', function (): void {
    foreach (range(1, 30) as $n) {
        lifecycleTrashedDaysAgo($this->editor, "Notice {$n}", 1);
    }

    $page = cmNavigate(cmSignIn($this->editor), '/admin/posts?tab=trash');

    cmWaitUntil($page, "document.querySelector('[aria-label=\"Actions for Notice 1\"]') !== null && document.scrollingElement.scrollHeight > window.innerHeight")
        ->assertScript('getComputedStyle(document.documentElement).scrollbarGutter', 'stable');

    $where = "(() => ({ width: document.documentElement.clientWidth, columns: [...document.querySelectorAll('thead th')].map((cell) => Math.round(cell.getBoundingClientRect().width)).join(',') }))()";
    $before = ($this->still)($page, $where);

    $page->click('[aria-label="More actions for Notice 1"]');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0");
    $page->click('[role="menu"] [data-action="delete"]');

    shellSettled($page, '[role="dialog"]');

    expect(($this->still)($page, $where))->toBe($before);
});

it('moves a post to the trash from its menu at once', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->click('[aria-label="More actions for Market day"]');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]')?.getAnimations().length === 0");
    $page->click('[role="menu"] [data-action="trash"]');

    cmWaitUntil($page, "![...document.querySelectorAll('[data-test=\"row-title\"]')].some((title) => title.textContent.trim() === 'Market day')");

    expect(Content::onlyTrashed()->whereKey($this->market->id)->exists())->toBeTrue();
});

it('opens Quick edit from its link', function (): void {
    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    $page->click('[aria-label="Actions for Market day"] [data-action="quick-edit"]');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]') !== null && [...document.querySelectorAll('[data-slot=\"sheet-content\"], [data-slot=\"sheet-overlay\"]')].every((element) => element.getAnimations().length === 0)")
        ->assertScript("document.querySelector('[data-slot=\"sheet-content\"] input[name=\"title\"]').value", 'Market day');
});

it('always shows the links on a touch screen', function (): void {
    $page = visit('/admin/login')
        ->on()->mobile()
        ->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/posts');

    ($this->drawn)($page);

    expect(($this->opacity)($page, 'Market day'))->toBe('1')
        ->and(($this->opacity)($page, 'Harbour news'))->toBe('1');
});

it('gives the other lists that share the row layer the same links, up to four on the line, the rest in its menu', function (string $path, string $label, string $line, string $menu, string $more): void {
    $item = cmItem($this->editor, 'Ferry fares');
    cmNext($item, 'pending', attributes: ['author_name' => 'Ada Guest']);
    taxonomyTerm('category', 'Transport');

    $page = cmSignIn($this->editor)->navigate($path);

    cmWaitUntil($page, "document.querySelector('[aria-label=\"{$label}\"]') !== null")
        ->assertScript("[...document.querySelectorAll('[aria-label=\"{$label}\"] [data-action]')].map((action) => action.dataset.action).join('|')", $line);

    if ($more !== '') {
        $page->click($menu);

        cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null")
            ->assertScript("[...document.querySelectorAll('[role=\"menu\"] [role=\"menuitem\"]')].map((item) => item.textContent.trim()).join('|')", $more);
    }
})->with([
    'a waiting comment' => ['/admin/comments', 'Actions for the comment by Ada Guest', 'approved|reply|quick-edit|details', '[aria-label="More actions for the comment by Ada Guest"]', 'Mark as spam|Move to trash'],
    'a category' => ['/admin/categories', 'Actions for Transport', 'edit|view', '[aria-label="More actions for Transport"]', 'Delete'],
]);

it('passes axe with every row\'s line shown, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = ($this->drawn)(cmSignIn($this->editor)->navigate('/admin/posts'));

    cmWaitUntil($page, "getComputedStyle(document.querySelector('[aria-label=\"Actions for Market day\"]')).opacity === '1'")
        ->assertScript("[...document.querySelectorAll('[aria-label=\"Actions for Market day\"] [data-action]')].every((action) => action.getBoundingClientRect().height >= 24)")
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('shows each row\'s actions without hovering in the lists that draw them as links, People and Types among them (#532)', function (string $path): void {
    $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    $page = cmNavigate(cmSignIn($admin), $path);

    cmWaitUntil($page, "document.querySelector('[data-test=\"row-links\"]') !== null")
        ->assertScript("[...document.querySelectorAll('[data-test=\"row-links\"]')].every((line) => getComputedStyle(line).opacity === '1')");
})->with(['/admin/users', '/admin/types']);
