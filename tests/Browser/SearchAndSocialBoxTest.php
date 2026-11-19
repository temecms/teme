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

// Search and social as a box under the article (I-3415,
// docs/architecture/design-system.md, 4.39): a strip that folds it, then
// Search, Social, Schema and Advanced as tabs, the checks after the fields.

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
    $this->harbour = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = "/admin/content/{$this->harbour->id}/edit";

    // The fields this person's autosave holds.
    $this->sbxSaved = fn (): array => json_decode((string) DB::table('content_autosaves')->where('content_id', $this->harbour->id)->value('fields'), true) ?? [];

    // Waits until what was typed last is saved (SearchSectionTest).
    $this->sbxSettled = function (mixed $page): mixed {
        $state = "document.querySelector('[data-test=\"save-state\"]')";

        cmWaitUntil($page, "{$state}?.dataset.state === 'saving'");

        return cmWaitUntil($page, "{$state}?.dataset.state === 'saved' && {$state}?.dataset.savedAt !== undefined");
    };

    $this->sbxBox = '[data-test="search-section"]';

    // The box's tabs by name, and the one panel on screen.
    $this->sbxTabs = "Array.from(document.querySelectorAll('[data-test=\"search-section\"] [role=\"tab\"]'), (tab) => tab.textContent.trim())";
    $this->sbxShownPanels = "Array.from(document.querySelectorAll('[data-test=\"search-section\"] [role=\"tabpanel\"]')).filter((panel) => panel.offsetParent !== null)";

    // Shows one tab, by its name.
    $this->sbxTab = function (mixed $page, string $name): void {
        $page->click("{$this->sbxBox} [role=\"tab\"]:text-is(\"{$name}\")");
        cmWaitUntil($page, "document.querySelector('[data-test=\"search-section\"] [role=\"tab\"][aria-selected=\"true\"]')?.textContent.trim() === ".json_encode($name, JSON_THROW_ON_ERROR));
    };

    // The search result's width, as the drawing gives it: 600 on a desktop, 400 on a phone.
    $this->sbxSerpWidth = "Math.round(document.querySelector('[data-test=\"serp\"]').getBoundingClientRect().width)";

    // The editor arrived: on a phone the menu is a drawer, so cmEditorSettled's folded menu never comes.
    $this->sbxArrived = fn (mixed $page, int $width): mixed => $width >= 1024
        ? cmEditorSettled($page)
        : cmWaitUntil($page, "document.querySelector('.teme-prose') !== null && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('shows Search, Social, Schema and Advanced as tabs, one panel at a time, Search first', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    cmWaitUntil($page, "{$this->sbxTabs}.join() === 'Search,Social,Schema,Advanced' && {$this->sbxShownPanels}.length === 1");
    $page->assertScript("document.querySelector('[data-test=\"search-section\"] [role=\"tab\"][aria-selected=\"true\"]').textContent.trim()", 'Search');

    // → moves to Social, and Social's panel is the one shown.
    $page->click("{$this->sbxBox} [role=\"tab\"]:text-is(\"Search\")");
    $page->page()->locator(':focus')->press('ArrowRight');
    cmWaitUntil($page, "document.activeElement?.textContent.trim() === 'Social' && document.activeElement.getAttribute('aria-selected') === 'true' && {$this->sbxShownPanels}.length === 1 && {$this->sbxShownPanels}[0].contains(document.getElementById('editor-social-title'))");
});

it('shows the result at true size, 600 wide on Desktop and 400 on Mobile, with the address only in the preview', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1600, 900), $this->edit)));

    cmWaitUntil($page, "{$this->sbxSerpWidth} === 600");
    $page->click("{$this->sbxBox} [data-slot=\"segmented-item\"]:text-is(\"Mobile\")");
    cmWaitUntil($page, "{$this->sbxSerpWidth} === 400");

    // The address is in the preview, and no field of the box holds it.
    $page->assertScript("(() => { const box = document.querySelector('[data-test=\"search-section\"]'); const path = '/harbour-news'; return document.querySelector('[data-test=\"serp\"]').textContent.includes('harbour-news') && Array.from(box.querySelectorAll('input, textarea')).every((field) => !field.value.includes(path)); })()");
});

it('keeps Social the same as search until something is typed there', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));
    $page->type('#editor-seo-title', 'Ferry fares rise');

    ($this->sbxTab)($page, 'Social');
    cmWaitUntil($page, "document.getElementById('editor-social-title').placeholder === 'Same as search: Ferry fares rise'");
    $page->assertSeeIn('[data-test="social-line"]', '0 of 3 set for sharing; the rest same as search.');

    $page->type('#editor-social-title', 'Fares are going up');
    cmWaitUntil($page, "document.querySelector('[data-test=\"social-line\"]').textContent === '1 of 3 set for sharing; the rest same as search.'");
    ($this->sbxSettled)($page);

    $saved = ($this->sbxSaved)();

    expect($saved[BuiltInFields::SOCIAL_TITLE] ?? null)->toBe('Fares are going up')
        ->and($saved[BuiltInFields::SOCIAL_DESCRIPTION] ?? null)->toBeNull()
        ->and($saved[BuiltInFields::SEO_TITLE] ?? null)->toBe('Ferry fares rise');
});

it('lays the label and guidance in a left column, stacking them on a narrow box', function (int $width, bool $side): void {
    $page = ($this->sbxArrived)(cmNavigate(cmSignIn($this->editor)->resize($width, 900), $this->edit), $width);
    cmOpenSearch($page);

    $where = $page->script("(() => { const label = document.querySelector('label[for=\"editor-seo-title\"]').getBoundingClientRect(); const field = document.getElementById('editor-seo-title').getBoundingClientRect(); return { beside: label.right <= field.left + 1 && label.left < field.left, above: label.bottom <= field.top + 1 }; })()");

    expect($side ? $where['beside'] : $where['above'])->toBeTrue();
})->with([
    'a desktop' => [1280, true],
    'a phone' => [375, false],
]);

it('puts the checks after the Search tab\'s fields, and a check\'s Fix opens its tab and focuses its field', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    $page->assertScript("(() => { const checks = document.querySelector('[data-test=\"search-section\"] [data-test=\"search-checks-toggle\"]'); const fields = ['editor-seo-title', 'editor-seo-description'].map((id) => document.getElementById(id)); return checks !== null && fields.every((field) => Boolean(field.compareDocumentPosition(checks) & Node.DOCUMENT_POSITION_FOLLOWING)); })()");

    // Hidden from search engines on Advanced, the Search engines check warns; its Fix leads back to Advanced's field.
    $page->click('#editor-advanced');
    $page->click('#editor-seo-noindex');
    ($this->sbxTab)($page, 'Search');
    $page->click('[data-test="search-checks-toggle"]');
    $page->click('[data-test="search-checks"] button[aria-label="Fix: Search engines"]');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-seo-noindex' && document.querySelector('[data-test=\"search-section\"] [role=\"tab\"][aria-selected=\"true\"]').textContent.trim() === 'Advanced'");
});

it('keeps what was typed across tabs and a fold', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));
    $page->type('#editor-seo-title', 'Ferry fares rise in the spring');

    ($this->sbxTab)($page, 'Social');
    $page->type('#editor-social-title', 'Fares are going up');
    $page->click('[data-test="search-toggle"]');
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === true");
    cmOpenSearch($page);
    ($this->sbxTab)($page, 'Search');

    $page->assertValue('#editor-seo-title', 'Ferry fares rise in the spring')
        ->assertValue('#editor-social-title', 'Fares are going up');
    ($this->sbxSettled)($page);

    expect(($this->sbxSaved)())->toMatchArray([
        BuiltInFields::SEO_TITLE => 'Ferry fares rise in the spring',
        BuiltInFields::SOCIAL_TITLE => 'Fares are going up',
    ]);
});

it('passes axe open on each tab, light and dark, at 1280 and 375', function (string $scheme, int $width): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $page = ($this->sbxArrived)(cmNavigate(cmSignIn($this->editor)->resize($width, 900), $this->edit), $width);
    cmOpenSearch($page);

    foreach (['Search', 'Social', 'Schema', 'Advanced'] as $tab) {
        ($this->sbxTab)($page, $tab);

        expect(cmAxeViolations($page))->toBe([])
            ->and(cmSmallTargets($page)['small'])->toBe([]);
    }
})->with(['light', 'dark'])->with([1280, 375]);

it('marks a tab whose fields hold a proposal while another tab is shown, so none waits unseen (I-3124)', function (): void {
    $author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $item = docSave(
        lifecycleDraft($author, 'Ferry fares rise'),
        $author,
        docWith(docText('Fares rise by 15% from 1 October.')),
    )->refresh();
    mdReady()->answer(mdAnswer());
    $edit = "/admin/content/{$item->id}/edit";
    $this->actingAs($author)->get($edit)->assertOk();

    // A tab's mark, and the description a screen reader hears with its name.
    $mark = fn (string $name): string => "(() => { const tab = Array.from(document.querySelectorAll('[data-test=\"search-section\"] [role=\"tab\"]')).find((each) => each.textContent.includes(".json_encode($name, JSON_THROW_ON_ERROR).")); return { mark: tab.querySelector('[data-test=\"tab-proposal\"]') !== null && tab.querySelector('[data-test=\"tab-proposal\"]').offsetParent !== null, said: (document.getElementById(tab.getAttribute('aria-describedby') ?? '')?.textContent ?? '') === 'proposal waiting' }; })()";

    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($author), $edit)));
    $page->click('[data-test="metadata-suggest"]');
    cmWaitUntil($page, "document.querySelector('[data-ai-proposal=\"seo_title\"]') !== null");

    // On Search, its own proposals are in view, and Social marks the two waiting there.
    expect($page->script($mark('Social')))->toBe(['mark' => true, 'said' => true])
        ->and($page->script($mark('Search')))->toBe(['mark' => false, 'said' => false]);

    // On Social, Search marks its two, and Social's mark goes.
    ($this->sbxTab)($page, 'Social');

    expect($page->script($mark('Search')))->toBe(['mark' => true, 'said' => true])
        ->and($page->script($mark('Social')))->toBe(['mark' => false, 'said' => false])
        ->and($page->script($mark('Schema')))->toBe(['mark' => false, 'said' => false]);
});

it('draws an empty meta description three lines tall, saying the excerpt stands in for it (#528)', function (): void {
    docSave($this->harbour, $this->editor, docWith(docText('Fares rise in October.')), [BuiltInFields::EXCERPT => 'Dearer crossings from October.']);

    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    $field = cmScriptArray($page, <<<'JS'
        () => {
            const field = document.getElementById('editor-seo-description');
            const style = getComputedStyle(field);
            const lines = (field.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom)) / parseFloat(style.lineHeight);

            return { value: field.value, lines: Math.round(lines * 10) / 10, placeholder: field.placeholder };
        }
        JS);

    expect($field['value'])->toBe('')
        ->and($field['lines'])->toBeGreaterThanOrEqual(3)
        ->and($field['placeholder'])->toBe('Using the excerpt: Dearer crossings from October.');
});

it('folds the whole box down to a 52-pixel header strip naming the result\'s title and how many things to check', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit));

    // What the folded box shows: its strip, and none of the preview, the tabs or the fields.
    $folded = $page->script(<<<'JS'
        () => {
            const box = document.querySelector('[data-test="search-section"]');
            const strip = box.querySelector('[data-test="search-strip"]');

            return {
                height: Math.round(strip.getBoundingClientRect().height),
                ground: getComputedStyle(strip).backgroundColor === getComputedStyle(document.documentElement).getPropertyValue('--chrome').trim() || strip.className.includes('bg-chrome'),
                name: document.getElementById('editor-search-label').textContent.trim(),
                summary: box.querySelector('[data-test="search-check-line"]').textContent.trim(),
                serp: box.querySelector('[data-test="serp"]')?.offsetParent ?? null,
                words: box.innerText,
            };
        }
        JS);

    expect($folded['height'])->toBe(52)
        ->and($folded['ground'])->toBeTrue()
        ->and($folded['name'])->toBe('Search and social')
        ->and($folded['summary'])->toMatch('/^Harbour news .+ · \d+ things to check$/')
        ->and($folded['serp'])->toBeNull()
        ->and($folded['words'])->not->toContain('How this')
        ->not->toContain('Every check is in the side panel.')
        ->not->toContain('Internal links. None');

    cmOpenSearch($page);
    cmWaitUntil($page, "document.querySelector('[data-test=\"serp\"]')?.offsetParent !== null");
});

it('draws underline tabs with icons, a warning triangle on Search while it has warnings', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    $tabs = $page->script(<<<'JS'
        () => Array.from(document.querySelectorAll('[data-test="search-section"] [role="tab"]'), (tab) => {
            const style = getComputedStyle(tab);

            return {
                name: tab.textContent.trim(),
                icon: tab.querySelector('svg')?.dataset.icon ?? null,
                underline: tab.getAttribute('aria-selected') === 'true' ? style.borderBottomWidth === '2px' && style.backgroundColor === 'rgba(0, 0, 0, 0)' : style.borderBottomColor === 'rgba(0, 0, 0, 0)',
            };
        })
        JS);

    expect($tabs)->toBe([
        ['name' => 'Search', 'icon' => 'warning', 'underline' => true],
        ['name' => 'Social', 'icon' => 'social', 'underline' => true],
        ['name' => 'Schema', 'icon' => 'schema', 'underline' => true],
        ['name' => 'Advanced', 'icon' => 'advanced', 'underline' => true],
    ]);
});

it('puts the result preview first on the Search tab: a Desktop and Mobile switch with icons, the site, the path, the cut title and the date', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit)));
    $page->type('#editor-seo-title', 'Ferry fares to Bonny Island rise fifteen percent from October, the operator says, blaming diesel');

    $preview = $page->script(<<<'JS'
        () => {
            const panel = document.querySelector('[data-test="search-section"] [role="tabpanel"]:not([hidden])');
            const first = panel.querySelector('[data-slot="box-row"]');
            const serp = document.querySelector('[data-test="serp"]');

            return {
                first: first.contains(serp),
                guide: first.querySelector('[data-slot="box-row-guide"]').innerText.replace(/\s+/g, ' ').trim(),
                icons: Array.from(first.querySelectorAll('[data-slot="segmented-item"]'), (item) => item.querySelector('svg') !== null),
                mark: serp.querySelector('[data-test="serp-mark"]').textContent.trim(),
                site: serp.querySelector('[data-test="serp-site"]').textContent.trim(),
                path: serp.querySelector('[data-test="serp-path"]').textContent.trim(),
                title: serp.querySelector('[data-test="serp-title"]').textContent.trim(),
                date: serp.querySelector('[data-test="serp-date"]')?.textContent ?? '',
                dateFirst: serp.querySelector('[data-test="serp-description"]').firstElementChild?.dataset.test === 'serp-date',
                font: getComputedStyle(serp.querySelector('[data-test="serp-title"]')).fontFamily === getComputedStyle(document.body).fontFamily,
            };
        }
        JS);

    expect($preview['first'])->toBeTrue()
        ->and($preview['guide'])->toBe('Result preview Desktop Mobile As a search result may show it.')
        ->and($preview['icons'])->toBe([true, true])
        ->and($preview['site'])->not->toBe('')
        ->and($preview['mark'])->toBe(mb_substr($preview['site'], 0, 1))
        ->and($preview['path'])->toEndWith('› harbour-news')
        ->and($preview['path'])->not->toContain('://')
        ->and($preview['title'])->toEndWith('…')
        ->and($preview['date'])->toEndWith(' — ')
        ->and($preview['dateFirst'])->toBeTrue()
        ->and($preview['font'])->toBeTrue();
});

it('draws the SEO title as two lines with the template as its placeholder, and one short length line over a bar', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit)));

    $title = fn (): array => $page->script(<<<'JS'
        () => {
            const field = document.getElementById('editor-seo-title');
            const bar = document.querySelector('[data-test="seo-title-bar"] [data-slot="progress-fill"]');

            return {
                tag: field.tagName,
                rows: field.rows,
                placeholder: field.placeholder,
                count: document.getElementById('editor-seo-title-count').textContent.trim(),
                warn: bar.className.includes('bg-warn'),
            };
        }
        JS);

    $empty = $title();

    expect($empty['tag'])->toBe('TEXTAREA')
        ->and($empty['rows'])->toBe(2)
        ->and($empty['placeholder'])->toStartWith('Using the title template: Harbour news')
        ->and($empty['count'])->toMatch('/^\d+ characters, from the template · about 60 show$/')
        ->and($empty['warn'])->toBeFalse();

    $page->type('#editor-seo-title', 'Ferry fares to Bonny Island rise fifteen percent from October, the operator says');
    cmWaitUntil($page, "document.getElementById('editor-seo-title-count').textContent.trim() === '80 characters · may be cut off'");

    expect($title()['warn'])->toBeTrue();
});

it('draws the meta description as a three-line text area while it is empty', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit)));

    $area = $page->script("(() => { const field = document.getElementById('editor-seo-description'); const style = getComputedStyle(field); return { lines: (field.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom)) / parseFloat(style.lineHeight), placeholder: field.placeholder }; })()");

    expect($area['lines'])->toBeGreaterThanOrEqual(2.9)
        ->and($area['placeholder'])->not->toBe('');
});

it('folds the checks after the fields, says how many to check, and a Fix moves focus to its field', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    $fold = $page->script(<<<'JS'
        () => {
            const toggle = document.querySelector('[data-test="search-checks-toggle"]');
            const description = document.getElementById('editor-seo-description');

            return {
                after: Boolean(description.compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_FOLLOWING),
                words: toggle.textContent.replace(/\s+/g, ' ').trim(),
                icon: toggle.querySelector('svg')?.dataset.icon ?? null,
                expanded: toggle.getAttribute('aria-expanded'),
            };
        }
        JS);

    expect($fold['after'])->toBeTrue()
        ->and($fold['words'])->toMatch('/^Checks · \d+ things to check$/')
        ->and($fold['icon'])->toBe('warning')
        ->and($fold['expanded'])->toBe('false');

    $page->click('[data-test="search-checks-toggle"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"search-checks\"]')?.offsetParent !== null");
    $page->assertSeeIn('[data-test="search-section"]', 'A warning never blocks publishing.');

    // The draft has no description: its Fix puts the cursor on the meta description.
    $page->click('[data-test="search-checks"] button[aria-label="Fix: Description"]');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-seo-description'");
});
