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

it('shows the search result and the social card as the SEO fields are typed, with counts in characters and about the pixels (I-882)', function (): void {
    $long = 'Ferry fares rise in the spring, and again in the summer, and once more before the winter timetable begins';

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit)))
        ->assertSeeIn('[data-test="serp"]', 'Harbour news')
        ->assertSeeIn('#editor-seo-title-count', 'From the title template:')
        ->type('#editor-seo-title', 'Ferry fares rise in the spring')
        ->assertSeeIn('[data-test="serp"]', 'Ferry fares rise in the spring')
        ->assertSeeIn('#editor-seo-title-count', '30 characters, about')
        ->assertDontSeeIn('#editor-seo-title-count', 'Results will cut it.')
        ->type('#editor-seo-description', 'What the new fares mean for the island’s commuters.');

    // The social card is on the Social tab.
    ($this->tab)($page, 'Social');
    $page->assertSeeIn('[data-test="social-card"]', 'What the new fares mean for the island’s commuters.')
        ->assertSeeIn('[data-test="social-from"]', 'Made from the SEO title and the meta description, with no image.');
    ($this->tab)($page, 'Search');
    $page->type('#editor-seo-title', $long)
        ->assertSeeIn('#editor-seo-title-count', 'Results will cut it.')
        ->assertSeeIn('[data-test="search-check-line"]', 'Title in results. Too long: about')
        ->assertSeeIn('[data-test="rail-checks"]', 'Too long: about');

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
        ->assertSeeIn('[data-test="canonical-chosen"]', 'Chosen: Ferry fares')
        ->assertSeeIn('[data-test="serp-note"]', 'Search engines are asked to list “Ferry fares” instead.')
        ->click('#editor-seo-noindex')
        ->assertSeeIn('[data-test="serp-note"]', 'Hidden from search engines, so it won’t appear in results.')
        ->assertSeeIn('#editor-canonical-hint', 'While it’s hidden from search engines, it has no canonical link.')
        ->assertSeeIn('[data-test="search-check-line"]', 'Search engines. Hidden from search engines when it’s published.')
        ->assertSeeIn('[data-test="rail-checks"]', 'Hidden from search engines when it’s published.')
        ->assertSeeIn('[data-test="advanced-summary"]', 'Hidden from search engines · Canonical: another item');

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
        ->assertDontSee('That address is on this site.')
        ->assertSeeIn('[data-test="serp-note"]', 'Search engines are asked to list https://example.org/original-story instead.');

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

it('starts closed with the search result at Google’s size, a small social card and one line for the search checks, opens in place with Edit and closes with Done (I-3124)', function (): void {
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
                titleSize: getComputedStyle(document.querySelector('[data-test="serp-title"]')).fontSize,
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

    // Closed: the result at the width results give it, one line, no fields and no tabs.
    $closed = $state();

    expect($closed['toggle'])->toBe('Open Search and social')
        ->and($closed['expanded'])->toBe('false')
        ->and($closed['seoTitle'])->toBeNull()
        ->and($closed['socialTitle'])->toBeNull()
        ->and($closed['tabs'])->toBe([])
        ->and($closed['serp']['width'])->toBe(632)
        ->and($closed['titleSize'])->toBe('20px')
        // The draft has no description, no excerpt and no links: the line names both warnings, then counts the rest.
        ->and($closed['line'])->toContain('Description. None, and no excerpt. Search engines will choose text from the page.')
        ->toContain('Internal links. None to other items on this site.')
        ->toMatch('/The other \d search checks pass\./')
        ->toContain('Every check is in the side panel.');

    // Open: the Search tab under the result; Social and Advanced on their own tabs, Advanced holding Hide and the canonical.
    cmOpenSearch($page);
    $open = $state();

    expect($open['toggle'])->toBe('Fold Search and social')
        ->and($open['expanded'])->toBe('true')
        ->and($open['tabs'])->toBe(['Search', 'Social', 'Schema', 'Advanced'])
        ->and($open['serp']['width'])->toBe(632)
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
        ->and($advanced['noindex']['top'])->toBeGreaterThan($advanced['serp']['top'])
        ->and($advanced['pageType']['left'])->toBeGreaterThan($advanced['noindex']['left']);

    // Done: closed again, as it started.
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

    // Closed: from the button, Tab reaches the check line's link, and the next Tab leaves the section.
    $page->keys('[data-test="search-toggle"]', 'Tab');
    expect($focused())->toBe('search-checks-link');

    $page->keys('[data-test="search-checks-link"]', 'Tab');
    expect($inSection())->toBeFalse();

    // Enter opens it; focus stays on the button, now Fold.
    $page->keys('[data-test="search-toggle"]', 'Enter');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === false");
    expect($focused())->toBe('search-toggle')
        ->and($page->script("() => document.activeElement.getAttribute('aria-label')"))->toBe('Fold Search and social');

    // Into the open box: the result's size follows the check line's link, then the tabs, then the SEO title.
    $page->keys('[data-test="search-checks-link"]', 'Tab');
    expect($page->script("() => document.activeElement?.closest('[data-slot=\"segmented\"]') !== null"))->toBeTrue();

    $page->keys('[data-test="search-section"] [role="tab"][aria-selected="true"]', 'Tab');
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

    // Done at once, before the save: the previews show what was typed, and the description's source is the meta description.
    $page->click('[data-test="search-toggle"]');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === true");
    ($this->shows)($page, '[data-test="serp"]', 'Ferry fares rise in the spring');
    ($this->shows)($page, '[data-test="description-from"]', 'Description: the meta description.');
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

it('says in the closed section where the page’s description comes from: the excerpt, the meta description, or nothing (I-3124, I-290)', function (): void {
    docSave($this->harbour, $this->editor, docWith(docText('Fares rise in October.')), [BuiltInFields::EXCERPT => 'Dearer crossings from October.']);

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));
    ($this->shows)($page, '[data-test="description-from"]', 'Description: from the excerpt.');
    ($this->shows)($page, '[data-test="serp"]', 'Dearer crossings from October.');

    cmOpenSearch($page)->type('#editor-seo-description', 'What the new fares mean for commuters.');
    $page->click('[data-test="search-toggle"]');
    ($this->shows)($page, '[data-test="description-from"]', 'Description: the meta description.');

    // Neither: the meta description emptied in the section, the excerpt in the writing area.
    cmOpenSearch($page)->clear('#editor-seo-description');
    $page->clear('#editor-excerpt')
        ->click('[data-test="search-toggle"]');
    ($this->shows)($page, '[data-test="description-from"]', 'Description: none. Search engines will choose text from the page.');
    ($this->shows)($page, '[data-test="search-check-line"]', 'Description. None, and no excerpt. Search engines will choose text from the page.');
});

it('goes from the side panel’s Checks to Search and social under a long post: open, its heading at the top of the writing column and focused (I-3124)', function (): void {
    docSave($this->harbour, $this->editor, docWith(...array_map(fn (int $paragraph): array => docText(trim(str_repeat('harbour tide ', 25))), range(1, 60))));

    $page = cmEditorSettled(cmSignIn($this->editor)->resize(1440, 900)->navigate($this->edit));
    ($this->shows)($page, '[data-test="rail-search-note"]', 'Search and social is under the post.');

    $page->click('[data-test="rail-search-note"] button');
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

    $page->click('[data-test="rail-search-note"] button');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]') === null");
    cmWaitUntil($page, "document.activeElement?.id === 'editor-search-label' && document.getElementById('editor-search-fields')?.hidden === false");
});

it('opens Search and social when a check in the side panel leads to one of its fields (I-3124, I-880)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));

    // The draft has no description and no excerpt, so the Description check warns, and its button leads to the meta description.
    $page->click('[data-test="rail-checks"] button:has-text("Description")');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-seo-description' && document.getElementById('editor-search-fields')?.hidden === false");
});

it('leads from the check line to the side panel’s Checks, opening the panel when it was closed, and says it in plain words in focus mode (I-3124)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate($this->edit));

    $page->click('[data-test="rail-toggle"]');
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"]') === null");

    $page->click('[data-test="search-checks-link"]');
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"]') !== null && document.activeElement?.id === 'rail-checks-trigger'");

    // Focus mode hides the side panel, so the words lead nowhere and are no button.
    $page->script(<<<'JS'
        () => document.querySelector('[data-test="editor-focus"]').click()
        JS);
    cmWaitUntil($page, "document.documentElement.classList.contains('editor-focus') && document.querySelector('[data-test=\"search-checks-link\"]') === null");
    ($this->shows)($page, '[data-test="search-check-line"]', 'Every check is in the side panel.');
});
