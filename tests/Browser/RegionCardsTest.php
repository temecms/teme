<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Pest\Browser\Playwright\Playwright;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    // The public page enforces its policy, so placed code that ran is code its hashes allowed (I-3375).
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [], 'teme.public_csp' => 'enforce']);
    themeActivate('default');

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // Code an administrator placed with a card: words a reader sees, and an inline script the page's policy allows by its hash.
    $this->entry = scAdd($this->admin, 'Sidebar ad', 'placed', '<div class="sidebar-ad"><p>Fresh bread at the harbour bakery</p><script>window.regionCode = 1;</script></div>');

    foreach (['Harbour news', 'Ferry fares', 'Market day', 'Lighthouse open'] as $title) {
        pageArticle($this->editor, $title);
        $this->travel(1)->minutes();
    }

    // Menus in the theme's slots, so the page draws no note that one is missing.
    foreach (['primary' => 'Primary', 'footer' => 'Footer'] as $slot => $name) {
        $menu = navCreate($this->admin, $name);
        navSave($this->admin, $menu, [['kind' => 'home']]);
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $this->admin->id));
    }

    $this->region = regionCreate($this->editor, 'default');
    $this->edit = "/admin/content/{$this->region->id}/edit";

    // Opens a card's settings from its More menu.
    $this->openSettings = function (mixed $page, string $label): mixed {
        // A card's head shows while the card is pointed at or chosen.
        $page->hover('[data-node-view-wrapper]:has(> [data-slot="card-head"] span:text-is("'.$label.'"))');
        $page->click('[data-slot="card-head"]:has(span:text-is("'.$label.'")) button[aria-label="More"]');
        cmWaitUntil($page, "document.querySelector('[role=\"menuitem\"]') !== null");
        $page->click('[role="menuitem"]:has-text("Edit")');

        return cmWaitUntil($page, "document.querySelector('[role=\"menuitem\"]') === null");
    };

    // The browser's own record of what the enforced policy blocked, once the page is parsed (PublicPolicyTest).
    $this->blocked = function (mixed $page): array {
        $timeout = Playwright::timeout();
        cmWaitUntil($page, "document.readyState !== 'loading'");

        return $page->script(<<<JS
            async () => {
                const seen = [];
                const observer = new ReportingObserver((reports) => seen.push(...reports), { types: ['csp-violation'], buffered: true });
                observer.observe();
                await new Promise((resolve) => setTimeout(resolve, Math.min(300, {$timeout})));
                seen.push(...observer.takeRecords());
                observer.disconnect();
                return seen.map((report) => report.body.effectiveDirective + ' ' + report.body.blockedURL);
            }
            JS);
    };

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
    addrGet('/harbour-news/')->assertOk();
});

it('places Featured posts and Custom HTML after the article, publishes, and the article page shows both under its policy', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit))
        ->click('[role="toolbar"] button:has-text("Insert")')
        ->click('[data-test="block-picker"] [role="option"]:has-text("Featured posts")')
        ->assertNotPresent('[data-test="block-picker"]');

    ($this->openSettings)($page, 'Featured posts');
    $page->type('.teme-prose input[type="number"]', '3');
    cmWaitUntil($page, "(document.querySelector('.teme-prose')?.innerText ?? '').includes('Latest 3 posts')");

    // Nothing is chosen yet, so Custom HTML opens on its panel.
    // The caret goes to the empty line after the card, where the next card goes.
    cmWaitUntil($page, "document.querySelector('.ProseMirror > p:last-child') !== null");
    $page->click('.ProseMirror > p:last-child')
        ->click('[role="toolbar"] button:has-text("Insert")')
        ->click('[data-test="block-picker"] [role="option"]:has-text("Custom HTML")')
        ->assertNotPresent('[data-test="block-picker"]')
        ->assertSee('Choose the code to show')
        ->click('.ProseMirror label:text-is("Sidebar ad")');
    cmWaitUntil($page, "(document.querySelector('.teme-prose')?.innerText ?? '').includes('Readers see it here, drawn exactly as it was written.')");

    // The rail shows the chosen card's settings; back to the region's, where Publish is.
    $page->click('[data-slot="pane-head"] button[aria-label^="Back to the"]');
    $page->click('[aria-label="Region settings"] button:text-is("Publish")');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"] button') !== null");
    $page->click('[role="dialog"] button:text-is("Publish")');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null && document.body.innerText.includes('Published.')");
    cmNoJavaScriptErrors($page);

    $region = Content::query()->findOrFail($this->region->id);
    $cards = array_map(fn (array $node): array => [$node['type'], $node['attrs']['source'] ?? $node['attrs']['entryId'] ?? null, $node['attrs']['count'] ?? null], array_values(array_filter($region->publishedRevision?->document['content'] ?? [], fn (array $node): bool => $node['type'] !== 'paragraph')));

    expect($region->status)->toBe('published')
        ->and($cards)->toBe([['featured-posts', 'latest', 3], ['site-code', $this->entry->id, null]]);

    $article = visit('/harbour-news/');
    cmWaitUntil($article, "document.querySelector('.card-site-code') !== null");

    $shown = $article->script(<<<'JS'
        () => {
            const region = document.querySelector('[data-region="after-content"]');
            const article = document.querySelector('article');
            return {
                after: article !== null && region !== null && (article.compareDocumentPosition(region) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0,
                listed: [...region.querySelectorAll('.card-featured-posts-list > *')].length,
                code: region.querySelector('.card-site-code')?.innerText.trim(),
                ran: window.regionCode === 1,
            };
        }
        JS);

    expect($shown)->toBe(['after' => true, 'listed' => 3, 'code' => 'Fresh bread at the harbour bakery', 'ran' => true])
        ->and(($this->blocked)($article))->toBe([]);
    $article->assertNoAccessibilityIssues(3);
    cmNoJavaScriptErrors($article);
});

it('passes axe with both cards\' panels open at :dataset', function (int $width, string $scheme): void {
    regionSave($this->region, $this->editor, [
        ['type' => 'featured-posts', 'attrs' => ['id' => (string) Str::ulid(), 'source' => 'latest', 'type' => 'post', 'count' => 3]],
        ['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'entryId' => $this->entry->id]],
    ]);
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $this->actingAs($this->editor)->get($this->edit)->assertOk();

    $page = cmNavigate(cmSignIn($this->editor)->resize($width, $width === 375 ? 812 : 900), $this->edit);
    // On a phone the menu is a drawer, so cmEditorSettled's folded menu never comes.
    $width === 375
        ? cmWaitUntil($page, "document.querySelector('.teme-prose') !== null")
        : cmEditorSettled($page);
    cmWaitUntil($page, "(document.querySelector('.teme-prose')?.innerText ?? '').includes('Readers see it here')");

    ($this->openSettings)($page, 'Featured posts');
    ($this->openSettings)($page, 'Custom HTML');
    cmWaitUntil($page, "['How many', 'Code to show'].every((words) => document.querySelector('.ProseMirror').innerText.includes(words))");

    $sideways = $page->script(<<<'JS'
        () => ({
            page: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
            cut: [...document.querySelectorAll('.teme-prose input, .teme-prose button, .teme-prose [role="radio"], .teme-prose [role="switch"]')]
                .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && (box.left < 0 || box.right > window.innerWidth); })
                .map((element) => element.id || element.outerHTML.slice(0, 80)),
        })
        JS);

    expect($sideways)->toBe(['page' => 0, 'cut' => []])
        ->and(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
})->with([
    '1280, light' => [1280, 'light'],
    '1280, dark' => [1280, 'dark'],
    '375, light' => [375, 'light'],
    '375, dark' => [375, 'dark'],
]);

it('gives a region its own checks in the rail, and none of a post\'s search checks (#529)', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit));

    cmWaitUntil($page, "document.querySelector('[data-test=\"rail-region-checks\"]') !== null")
        ->assertScript("[...document.querySelectorAll('[data-test=\"rail-region-checks\"] [data-check]')].map((check) => check.dataset.check).join('|')", 'blocks|cards|alt')
        ->assertScript("document.querySelector('[data-test=\"rail-region-checks\"] [data-check=\"blocks\"]').textContent.includes('Empty. The theme draws nothing here until the region has something in it.')")
        ->assertScript("document.querySelector('[data-test=\"rail-region-checks\"] [data-check=\"alt\"]').textContent.includes('No images without alt text.')")
        ->assertSeeIn('[data-test="rail-region-checks-note"]', 'A warning never blocks saving a draft. Publishing is refused while the region is over its limit or holds a card it does not take.')
        ->assertNotPresent('[data-test="rail-checks"]')
        ->assertNotPresent('[data-test="rail-search-note"]');
});
