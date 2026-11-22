<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->ferry = addrPublished($this->editor, 'Ferry fares');
    $this->harbour = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = "/admin/content/{$this->harbour->id}/edit";

    // The fields the editor last saved: this person's autosave of the article.
    $this->saved = fn (): array => json_decode((string) DB::table('content_autosaves')->where('content_id', $this->harbour->id)->value('fields'), true) ?? [];

    // Waits until what was typed last is saved: first until the save state's hook has noticed the change, then until it says saved, with a time (issue #83).
    $this->settled = function (mixed $page): mixed {
        $state = "document.querySelector('[data-test=\"save-state\"]')";

        cmWaitUntil($page, "{$state}?.dataset.state === 'saving'");

        return cmWaitUntil($page, "{$state}?.dataset.state === 'saved' && {$state}?.dataset.savedAt !== undefined");
    };

    // Waits until an element's words include these.
    $this->shows = fn (mixed $page, string $selector, string $text): mixed => cmWaitUntil($page, '(document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).")?.innerText ?? '').includes(".json_encode($text, JSON_THROW_ON_ERROR).')');

    // Shows one of the box's tabs, by its name (I-3415).
    $this->tab = function (mixed $page, string $name): mixed {
        $page->click('[data-test="search-section"] [role="tab"]:text-is("'.$name.'")');

        return cmWaitUntil($page, "document.querySelector('[data-test=\"search-section\"] [role=\"tab\"][aria-selected=\"true\"]')?.textContent.trim() === ".json_encode($name, JSON_THROW_ON_ERROR));
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('passes axe on the editor in the light and dark schemes, every target 24 pixels or more', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate($this->edit)
        ->assertSee('Search and social')
        // The editor folds the admin sidebar as it opens; axe reads its labels once they have faded.
        ->assertScript("[...document.querySelectorAll('[data-sidebar=\"group-label\"]')].flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length", 0);

    expect(cmAxeViolations($page))->toBe([]);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('shows the search result and the social card as the SEO fields are typed, with a length line and a bar for each (I-882)', function (): void {
    $long = 'Ferry fares rise in the spring, and again in the summer, and once more before the winter timetable begins';

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)))
        ->assertSeeIn('[data-test="serp"]', 'Harbour news')
        ->assertSeeIn('#editor-seo-title-count', 'characters, from the template · about 60 show')
        ->type('#editor-seo-title', 'Ferry fares rise in the spring')
        ->assertSeeIn('[data-test="serp"]', 'Ferry fares rise in the spring')
        ->assertSeeIn('#editor-seo-title-count', '30 characters · about 60 show')
        ->assertDontSeeIn('#editor-seo-title-count', 'may be cut off')
        ->type('#editor-seo-description', 'What the new fares mean for the island’s commuters.');

    // The social card is on the Social tab.
    ($this->tab)($page, 'Social');
    $page->assertSeeIn('[data-test="social-card"]', 'What the new fares mean for the island’s commuters.')
        ->assertSeeIn('[data-test="social-from"]', 'Made from the SEO title and the meta description, with no image.');
    ($this->tab)($page, 'Search');
    $page->type('#editor-seo-title', $long)
        ->assertSeeIn('#editor-seo-title-count', '105 characters · may be cut off')
        ->click('[data-test="search-checks-toggle"]')
        ->assertSeeIn('[data-test="search-checks"]', 'Title in results warning Too long: about')
        ->assertSeeIn('[data-test="rail-search-note"]', 'Search and social:');

    ($this->settled)($page);

    expect(($this->saved)())->toMatchArray([
        BuiltInFields::SEO_TITLE => $long,
        BuiltInFields::META_DESCRIPTION => 'What the new fares mean for the island’s commuters.',
    ]);
});

it('hides the item and names another item as its original, both in Advanced and saved with it, and says what each does in the section and the rail (I-292, I-693, I-880, I-882)', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)))
        ->click('#editor-advanced')
        ->click('#editor-canonical-item')
        ->type('#editor-canonical-search', 'Ferry')
        ->click('[data-test="canonical-option"] >> text="Ferry fares"')
        ->assertSeeIn('[data-test="canonical-chosen"]', 'Chosen: Ferry fares');
    // The result preview, on the Search tab, says what each does.
    ($this->tab)($page, 'Search');
    $page->assertSeeIn('[data-test="serp-note"]', 'Search engines are asked to list “Ferry fares” instead.');
    ($this->tab)($page, 'Advanced');
    $page->click('#editor-seo-noindex')
        ->assertSeeIn('#editor-canonical-hint', 'While it’s hidden from search engines, it has no canonical link.')
        ->assertSeeIn('[data-test="rail-search-note"]', 'things to check')
        ->assertSeeIn('[data-test="advanced-summary"]', 'Hidden from search engines · Canonical: another item');
    ($this->tab)($page, 'Search');
    $page->assertSeeIn('[data-test="serp-note"]', 'Hidden from search engines, so it won’t appear in results.')
        ->click('[data-test="search-checks-toggle"]')
        ->assertSeeIn('[data-test="search-checks"]', 'Search engines warning Hidden from search engines when it’s published.');

    ($this->settled)($page);

    expect(($this->saved)())->toMatchArray([BuiltInFields::NOINDEX => true, BuiltInFields::CANONICAL => ['item' => $this->ferry->id]]);
});

it('keeps saving while the canonical\'s address is half typed, refuses this site\'s own in the box, and stores a whole outside address (I-292)', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)));
    // The site's own address, as the browser's server is serving it now.
    $own = url('/ferry-fares/');

    $page->click('#editor-advanced')
        ->click('#editor-canonical-address')
        ->type('#editor-canonical-url', 'https://')
        ->assertSeeIn('#editor-canonical-url-error', 'Enter the whole address, starting https://.');
    ($this->tab)($page, 'Social');
    $page->type('#editor-social-title', 'Fares are going up');

    ($this->settled)($page);

    expect(($this->saved)())->toMatchArray([BuiltInFields::SOCIAL_TITLE => 'Fares are going up'])
        ->and(($this->saved)()[BuiltInFields::CANONICAL] ?? null)->toBeNull();

    ($this->tab)($page, 'Advanced');
    $page->type('#editor-canonical-url', $own)
        ->assertSeeIn('#editor-canonical-url-error', 'That address is on this site. Choose its item instead, so the link survives a move.')
        ->type('#editor-canonical-url', 'https://example.org/original-story')
        ->assertDontSee('That address is on this site.');
    ($this->tab)($page, 'Search');
    $page->assertSeeIn('[data-test="serp-note"]', 'Search engines are asked to list https://example.org/original-story instead.');

    ($this->settled)($page);

    expect(($this->saved)()[BuiltInFields::CANONICAL] ?? null)->toBe(['address' => 'https://example.org/original-story']);
});

it('chooses the social image from the library, and the card is made from it (I-293)', function (): void {
    $image = pageImage($this->editor, 1200, 630);

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)));
    ($this->tab)($page, 'Social');
    $page->assertSeeIn('[data-test="social-from"]', 'with no description and no image')
        ->click('#editor-social-image')
        ->assertScript("(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()", 0)
        ->click('[role="dialog"] [role="option"]')
        ->click('[data-test="media-modal-use"]')
        ->assertNotPresent('[role="dialog"]')
        ->assertSeeIn('[data-test="social-from"]', 'the social image')
        ->assertPresent('[data-test="social-card"] img');

    ($this->settled)($page);

    expect(($this->saved)()[BuiltInFields::SOCIAL_IMAGE]['id'] ?? null)->toBe($image->id);
});

it('starts folded to its header strip, opens in place with the search result at its true size first, a small social card on Social, and folds again (I-3124, I-3415)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->resize(1920, 1080)->navigate($this->edit));

    // What the section shows: its button, its height, and where its parts sit, null for a part not shown.
    $state = fn (): array => cmScriptArray($page, <<<'JS'
        () => {
            const box = (selector) => {
                const element = document.querySelector(selector);

                if (element === null || element.offsetParent === null) {
                    return null;
                }

                const rect = element.getBoundingClientRect();

                return { left: Math.round(rect.left), top: Math.round(rect.top), width: Math.round(rect.width) };
            };
            const toggle = document.querySelector('[data-test="search-toggle"]');

            return {
                toggle: toggle.getAttribute('aria-label'),
                expanded: toggle.getAttribute('aria-expanded'),
                height: document.querySelector('[data-test="search-section"]').offsetHeight,
                serp: box('[data-test="serp"]'),
                card: box('[data-test="social-card"]'),
                titleSize: document.querySelector('[data-test="serp-title"]') === null ? null : getComputedStyle(document.querySelector('[data-test="serp-title"]')).fontSize,
                line: document.querySelector('[data-test="search-check-line"]')?.innerText ?? '',
                summary: document.querySelector('[data-test="advanced-summary"]')?.innerText ?? '',
                advanced: document.querySelector('#editor-advanced')?.dataset.state ?? null,
                tabs: [...document.querySelectorAll('[data-test="search-section"] [role="tab"]')].filter((tab) => tab.offsetParent !== null).map((tab) => tab.textContent.trim()),
                seoTitle: box('#editor-seo-title'),
                socialTitle: box('#editor-social-title'),
                noindex: box('#editor-seo-noindex'),
                canonical: box('#editor-canonical-self'),
                pageType: box('#editor-page-type'),
            };
        }
        JS);

    // Folded: only the strip, which names the result's title and how many things to check.
    $closed = $state();

    expect($closed['toggle'])->toBe('Open Search and social')
        ->and($closed['expanded'])->toBe('false')
        ->and($closed['seoTitle'])->toBeNull()
        ->and($closed['socialTitle'])->toBeNull()
        ->and($closed['tabs'])->toBe([])
        ->and($closed['serp'])->toBeNull()
        ->and($closed['height'])->toBe(52)
        // The draft has no description, no excerpt and no links: two things to check at least.
        ->and($closed['line'])->toMatch('/^Harbour news .* · \d+ things to check$/');

    // Open: the tabs, then the result as the Search tab's first row; Social and Advanced on their own tabs, Advanced holding Hide and the canonical.
    cmOpenSearch($page);
    $open = $state();

    expect($open['toggle'])->toBe('Fold Search and social')
        ->and($open['expanded'])->toBe('true')
        ->and($open['tabs'])->toBe(['Search', 'Social', 'Schema', 'Advanced'])
        ->and($open['serp']['width'])->toBe(600)
        ->and($open['titleSize'])->toBe('18px')
        ->and($open['seoTitle']['top'])->toBeGreaterThan($open['serp']['top'])
        ->and($open['socialTitle'])->toBeNull()
        ->and($open['advanced'])->toBe('inactive')
        ->and($open['summary'])->toBe('Hide from search engines, canonical address, page and article type, snippet')
        ->and($open['noindex'])->toBeNull()
        ->and($open['canonical'])->toBeNull()
        ->and($open['height'])->toBeGreaterThan($closed['height'] + 200);

    // Social: a small social card.
    ($this->tab)($page, 'Social');
    $social = $state();

    expect($social['card']['width'])->toBe(280)
        ->and($social['socialTitle'])->not->toBeNull()
        ->and($social['seoTitle'])->toBeNull();

    // Advanced: Hide from search engines and the canonical in its first column, the page type in its second.
    $page->click('#editor-advanced');
    cmWaitUntil($page, "document.querySelector('#editor-advanced')?.dataset.state === 'active' && document.querySelector('#editor-seo-noindex')?.offsetParent !== null");
    $advanced = $state();

    expect($advanced['noindex'])->not->toBeNull()
        ->and($advanced['canonical'])->not->toBeNull()
        ->and($advanced['serp'])->toBeNull()
        ->and($advanced['pageType']['left'])->toBeGreaterThan($advanced['noindex']['left']);

    // Fold: closed again, as it started.
    $page->click('[data-test="search-toggle"]');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === true");
    $done = $state();

    expect($done['toggle'])->toBe('Open Search and social')
        ->and($done['seoTitle'])->toBeNull()
        ->and($done['height'])->toBe($closed['height']);
});

it('opens and closes with the keyboard, keeps focus on its button, and leaves its hidden fields out of the tab order (I-3124)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));
    $focused = fn (): string => (string) $page->script('() => document.activeElement?.id || document.activeElement?.dataset.test || document.activeElement?.tagName');
    $inSection = fn (): bool => (bool) $page->script("() => document.querySelector('[data-test=\"search-section\"]').contains(document.activeElement)");

    // Folded: from the button, the next Tab leaves the section.
    $page->keys('[data-test="search-toggle"]', 'Tab');
    expect($inSection())->toBeFalse();

    // Enter opens it; focus stays on the button, now Fold.
    $page->keys('[data-test="search-toggle"]', 'Enter');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === false");
    expect($focused())->toBe('search-toggle')
        ->and($page->script("() => document.activeElement.getAttribute('aria-label')"))->toBe('Fold Search and social');

    // Into the open box: the tabs, the Search panel, the result's size in its first row, then the SEO title.
    $page->keys('[data-test="search-toggle"]', 'Tab');
    expect($page->script("() => document.activeElement?.getAttribute('role')"))->toBe('tab');

    $page->page()->locator(':focus')->press('Tab');
    $page->page()->locator(':focus')->press('Tab');
    expect($page->script("() => document.activeElement?.closest('[data-slot=\"segmented\"]') !== null"))->toBeTrue();

    $page->page()->locator(':focus')->press('Tab');
    expect($focused())->toBe('editor-seo-title');

    // Space opens Advanced, and Space ticks Hide from search engines.
    $page->keys('#editor-advanced', 'Space');
    cmWaitUntil($page, "document.querySelector('#editor-advanced')?.dataset.state === 'active'");
    $page->keys('#editor-seo-noindex', 'Space');
    cmWaitUntil($page, "document.querySelector('#editor-seo-noindex')?.dataset.state === 'checked'");
    ($this->shows)($page, '[data-test="advanced-summary"]', 'Hidden from search engines');

    // Enter on Fold closes it; focus stays on the button, now Open.
    $page->keys('[data-test="search-toggle"]', 'Enter');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === true");
    expect($focused())->toBe('search-toggle')
        ->and($page->script("() => document.activeElement.getAttribute('aria-label')"))->toBe('Open Search and social');

    ($this->settled)($page);

    expect(($this->saved)())->toMatchArray([BuiltInFields::NOINDEX => true]);
});

it('keeps everything typed when Done closes it and Edit opens it again, the half-typed canonical address too, and saves what is whole (I-3124, I-292)', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)))
        ->type('#editor-seo-title', 'Ferry fares rise in the spring')
        ->type('#editor-seo-description', 'What the new fares mean for the island’s commuters.');
    ($this->tab)($page, 'Social');
    $page->type('#editor-social-title', 'Fares are going up')
        ->click('#editor-advanced')
        ->click('#editor-canonical-address')
        ->type('#editor-canonical-url', 'https://');

    // Folded at once, before the save: the strip names the title that was typed.
    $page->click('[data-test="search-toggle"]');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === true");
    ($this->shows)($page, '[data-test="search-check-line"]', 'Ferry fares rise in the spring');
    ($this->settled)($page);

    expect(($this->saved)())->toMatchArray([
        BuiltInFields::SEO_TITLE => 'Ferry fares rise in the spring',
        BuiltInFields::META_DESCRIPTION => 'What the new fares mean for the island’s commuters.',
        BuiltInFields::SOCIAL_TITLE => 'Fares are going up',
    ])->and(($this->saved)()[BuiltInFields::CANONICAL] ?? null)->toBeNull();

    // Open again: every field as it was left, the outside address still half typed, Advanced still shown.
    cmOpenSearch($page)
        ->assertValue('#editor-seo-title', 'Ferry fares rise in the spring')
        ->assertValue('#editor-seo-description', 'What the new fares mean for the island’s commuters.')
        ->assertValue('#editor-social-title', 'Fares are going up')
        ->assertChecked('#editor-canonical-address')
        ->assertValue('#editor-canonical-url', 'https://')
        ->assertAttribute('#editor-advanced', 'data-state', 'active')
        ->assertSeeIn('#editor-canonical-url-error', 'Enter the whole address, starting https://.');
});

it('says beside the meta description where the page’s description comes from: the excerpt, the meta description, or nothing (I-3124, I-290)', function (): void {
    docSave($this->harbour, $this->editor, docWith(docText('Fares rise in October.')), [BuiltInFields::EXCERPT => 'Dearer crossings from October.']);

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)));
    ($this->shows)($page, '#editor-seo-description-count', 'characters, from the excerpt');
    ($this->shows)($page, '[data-test="serp"]', 'Dearer crossings from October.');
    $page->assertAttribute('#editor-seo-description', 'placeholder', 'Dearer crossings from October.');

    $page->type('#editor-seo-description', 'What the new fares mean for commuters.');
    ($this->shows)($page, '#editor-seo-description-count', '38 characters · about 155 show');

    // Neither: the meta description emptied in the box, the excerpt in the writing area.
    $page->clear('#editor-seo-description');
    $page->clear('#editor-excerpt');
    ($this->shows)($page, '#editor-seo-description-count', 'Empty');
    $page->assertAttribute('#editor-seo-description', 'placeholder', 'No excerpt either: search engines will choose text from the page');
    $page->click('[data-test="search-checks-toggle"]');
    ($this->shows)($page, '[data-test="search-checks"]', 'None, and no excerpt. Search engines will choose text from the page.');
});

it('goes from the side panel’s Checks to Search and social under a long post: open, its heading at the top of the writing column and focused (I-3124)', function (): void {
    docSave($this->harbour, $this->editor, docWith(...array_map(fn (int $paragraph): array => docText(trim(str_repeat('harbour tide ', 25))), range(1, 60))));

    $page = cmEditorSettled(cmSignIn($this->editor)->resize(1440, 900)->navigate($this->edit));
    ($this->shows)($page, '[data-test="rail-search-note"]', 'Search and social:');

    $page->click('[data-test="rail-search-note"]');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-search-label' && document.getElementById('editor-search-fields')?.hidden === false");

    $where = $page->script(<<<'JS'
        () => {
            const writing = document.querySelector('[data-slot="editor-writing"]');
            const column = writing.getBoundingClientRect();
            const heading = document.getElementById('editor-search-label').getBoundingClientRect();

            return { scrolled: writing.scrollTop, inView: heading.top >= column.top - 1 && heading.bottom <= column.bottom + 1, window: window.scrollY };
        }
        JS);

    expect($where['scrolled'])->toBeGreaterThan(0)
        ->and($where['inView'])->toBeTrue()
        ->and($where['window'])->toBe(0);
});

it('closes the side panel’s sheet on a phone before going to Search and social (I-3124)', function (): void {
    $page = cmSignIn($this->editor)->resize(390, 844)->navigate($this->edit);
    // On a phone the menu is a drawer, so cmEditorSettled's folded menu never comes.
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");

    $page->click('[data-test="rail-toggle"]');
    cmWaitUntil($page, "(() => { const parts = [...document.querySelectorAll('[data-slot=\"sheet-content\"], [data-slot=\"sheet-overlay\"]')]; return parts.length > 0 && parts.flatMap((element) => element.getAnimations()).every((animation) => animation.playState !== 'running'); })()");

    $page->click('[data-test="rail-search-note"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]') === null");
    cmWaitUntil($page, "document.activeElement?.id === 'editor-search-label' && document.getElementById('editor-search-fields')?.hidden === false");
});

it('keeps the side panel\'s Checks to the writing, counting Search and social\'s, and puts the cursor on what a writing check names (I-880)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));

    // The draft has no excerpt, so the excerpt check warns, and its Show leads to the excerpt.
    $page->assertScript("[...document.querySelectorAll('[data-test=\"rail-checks\"] > li')].map((check) => check.dataset.test.replace('rail-check-', '')).join('|')", 'title|excerpt|featured|alt|headings')
        ->assertSeeIn('[data-test="rail-search-note"]', 'things to check')
        ->click('[data-test="rail-check-excerpt"] button:has-text("Show")');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-excerpt'");
});

it('keeps the search checks in the box, after its fields, with no link away to the side panel, in focus mode too (I-3124, I-3415)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));

    $page->script(<<<'JS'
        () => document.querySelector('[data-test="editor-focus"]').click()
        JS);
    cmWaitUntil($page, "document.documentElement.classList.contains('editor-focus')");

    cmOpenSearch($page)->click('[data-test="search-checks-toggle"]');
    ($this->shows)($page, '[data-test="search-checks"]', 'Internal links');
    $page->assertDontSeeIn('[data-test="search-section"]', 'Every check is in the side panel.')
        ->assertNotPresent('[data-test="search-checks-link"]');
});
