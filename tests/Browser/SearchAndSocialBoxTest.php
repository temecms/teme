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

    // The search result's text width, inside its padding.
    $this->sbxSerpWidth = "(() => { const serp = document.querySelector('[data-test=\"serp\"]'); const style = getComputedStyle(serp); return Math.round(serp.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)); })()";

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
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit)));

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

it('puts the checks after the fields, and a check\'s Fix opens its tab and focuses its field', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit)));

    $page->assertScript("(() => { const checks = document.querySelector('[data-test=\"search-section\"] [data-test=\"search-checks\"]'); const panels = document.querySelectorAll('[data-test=\"search-section\"] [role=\"tabpanel\"]'); return checks !== null && Array.from(panels).every((panel) => Boolean(panel.compareDocumentPosition(checks) & Node.DOCUMENT_POSITION_FOLLOWING)); })()");

    // The draft has no description, so its check warns; from Social, it leads back to Search's field.
    ($this->sbxTab)($page, 'Social');
    $page->click('[data-test="search-checks"] button:has-text("Description")');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-seo-description' && document.querySelector('[data-test=\"search-section\"] [role=\"tab\"][aria-selected=\"true\"]').textContent.trim() === 'Search'");
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
