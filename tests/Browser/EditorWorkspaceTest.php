<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // 200 paragraphs of 50 words: a 10,000-word article.
    $this->long = docSave(lifecycleDraft($this->editor, 'Harbour almanac'), $this->editor, docWith(
        ...array_map(fn (int $paragraph): array => docText(trim(str_repeat('harbour tide ', 25))), range(1, 200)),
    ));
    // Visited with cmNavigate(): the article can take longer to load than navigate()'s one-second attempts (#241).
    $this->edit = "/admin/content/{$this->long->id}/edit";

    // Where the editor's parts sit on the screen, in whole pixels, and how far each has scrolled.
    $this->where = fn (mixed $page): array => cmScriptArray($page, <<<'JS'
        () => {
            const top = (selector) => {
                const element = document.querySelector(selector);

                return element === null || element.offsetParent === null ? null : Math.round(element.getBoundingClientRect().top);
            };
            const writing = document.querySelector('[data-slot="editor-writing"]');
            const rail = document.querySelector('aside[aria-label="Post settings"]');
            const railScroller = rail?.querySelector(':scope > [data-slot="scroll-area"]') ?? null;

            return {
                window: Math.round(window.scrollY),
                pageFits: document.scrollingElement.scrollHeight <= window.innerHeight,
                topBar: top('[data-slot="app-header"]'),
                header: top('[data-slot="editor-shell"] > header'),
                writing: top('[data-slot="editor-writing"]'),
                toolbar: top('[role="toolbar"][aria-label="Formatting"]'),
                rail: top('aside[aria-label="Post settings"]'),
                writingScrolled: Math.round(writing.scrollTop),
                writingAtEnd: writing.scrollTop + writing.clientHeight >= writing.scrollHeight - 1,
                railScrolled: railScroller === null ? null : Math.round(railScroller.scrollTop),
            };
        }
        JS);

    // Scrolls one element to its end, as far as it goes.
    $this->scrollToEnd = fn (mixed $page, string $selector): mixed => $page->script('() => { const element = document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).'); element.scrollTop = element.scrollHeight; }');

    // Scrolls the writing column to its middle, inside the article's body. The
    // toolbar is sticky inside the body's own grid (prose-editor.tsx), so past
    // the body, in the panels under it, it leaves with that grid, as it should.
    $this->scrollToMiddle = fn (mixed $page): mixed => $page->script('() => { const w = document.querySelector(\'[data-slot="editor-writing"]\'); w.scrollTop = w.scrollHeight / 2; }');

    // Waits until the writing column has far more than it shows: it is the article's scroller, not the window.
    $this->columnScrolls = fn (mixed $page): mixed => cmWaitUntil($page, "(() => { const writing = document.querySelector('[data-slot=\"editor-writing\"]'); return writing !== null && writing.scrollHeight > writing.clientHeight * 5; })()");

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('keeps the editor\'s header, its toolbar and its side panel in view while a 10,000-word article scrolls in its own column', function (): void {
    expect($this->long->workingRevision->word_count)->toBeGreaterThanOrEqual(10000);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 640), $this->edit));
    ($this->columnScrolls)($page);

    // The side panel holds more than it shows, so its scroll below is real; its scroll area is what scrolls.
    cmWaitUntil($page, "(() => { const rail = document.querySelector('aside[aria-label=\"Post settings\"] > [data-slot=\"scroll-area\"]'); return rail !== null && rail.scrollHeight > rail.clientHeight; })()");

    $before = ($this->where)($page);

    // Halfway down the article: the header, the toolbar and the side panel are where they were, or stuck.
    ($this->scrollToMiddle)($page);
    cmWaitUntil($page, "document.querySelector('[data-slot=\"editor-writing\"]').scrollTop > 0");
    $middle = ($this->where)($page);

    expect($middle['window'])->toBe(0)
        ->and($middle['header'])->toBe($before['header'])
        ->and($middle['rail'])->toBe($before['rail'])
        ->and($middle['toolbar'])->toEqualWithDelta($middle['writing'], 1);

    // At the very end the column has scrolled all of it, and the page itself never moved.
    ($this->scrollToEnd)($page, '[data-slot="editor-writing"]');
    $end = ($this->where)($page);

    expect($end['window'])->toBe(0)
        ->and($end['pageFits'])->toBeTrue()
        ->and($end['writingAtEnd'])->toBeTrue();

    // Back to the middle; the side panel scrolls on its own, and the article stays where it was.
    ($this->scrollToMiddle)($page);
    $after = ($this->where)($page);
    ($this->scrollToEnd)($page, 'aside[aria-label="Post settings"] > [data-slot="scroll-area"]');
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"] > [data-slot=\"scroll-area\"]').scrollTop > 0");
    $railed = ($this->where)($page);

    expect($railed['writingScrolled'])->toBe($after['writingScrolled'])
        ->and($railed['rail'])->toBe($before['rail'])
        ->and($railed['header'])->toBe($before['header'])
        ->and($railed['window'])->toBe(0);
});

it('fits the workspace beneath a site notice, with the side panel closed and in focus mode', function (): void {
    // An administrator sees the search rebuild's notice above every screen, the editor's too.
    $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    Cache::put(ReindexSearchJob::PROGRESS, 120, now()->addHour());
    $this->actingAs($admin)->get($this->edit)->assertOk();

    $page = cmEditorSettled(cmNavigate(cmSignIn($admin)->resize(1280, 640), $this->edit));
    cmWaitUntil($page, "document.querySelector('[data-test=\"site-notice\"]') !== null");
    ($this->columnScrolls)($page);

    // In view halfway down, at the end the column has scrolled all of it, and the page never moves.
    $fits = function (mixed $page): array {
        ($this->scrollToMiddle)($page);
        cmWaitUntil($page, "document.querySelector('[data-slot=\"editor-writing\"]').scrollTop > 0");
        $middle = ($this->where)($page);

        expect($middle['window'])->toBe(0)
            ->and($middle['toolbar'])->toEqualWithDelta($middle['writing'], 1);

        ($this->scrollToEnd)($page, '[data-slot="editor-writing"]');
        $end = ($this->where)($page);

        expect($end['window'])->toBe(0)
            ->and($end['pageFits'])->toBeTrue()
            ->and($end['writingAtEnd'])->toBeTrue();

        return $middle;
    };

    $noticed = $fits($page);

    // The side panel closed: the column takes its place and still scrolls on its own.
    $page->click('[data-slot="editor-shell"] > header [aria-label="Hide settings"]');
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"]') === null");
    $closed = $fits($page);

    expect($closed['header'])->toBe($noticed['header']);

    // Focus mode hides the top bar; the workspace fills what it leaves.
    $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-slot="editor-shell"] > header button')].find((button) => button.textContent.trim() === 'Focus').click()
        JS);
    cmWaitUntil($page, "document.documentElement.classList.contains('editor-focus')");
    $focused = $fits($page);

    expect($focused['topBar'])->toBeNull();
});

it('keeps the toolbar in view on a phone, where the side panel stays a sheet', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(390, 844), $this->edit);
    // On a phone the menu is a drawer, so cmEditorSettled's folded menu never comes.
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");
    ($this->columnScrolls)($page);

    ($this->scrollToMiddle)($page);
    cmWaitUntil($page, "document.querySelector('[data-slot=\"editor-writing\"]').scrollTop > 0");
    $phone = ($this->where)($page);

    expect($phone['rail'])->toBeNull()
        ->and($phone['window'])->toBe(0)
        ->and($phone['topBar'])->toBe(0)
        ->and($phone['toolbar'])->toEqualWithDelta($phone['writing'], 1);

    ($this->scrollToEnd)($page, '[data-slot="editor-writing"]');
    $end = ($this->where)($page);

    expect($end['window'])->toBe(0)
        ->and($end['pageFits'])->toBeTrue()
        ->and($end['writingAtEnd'])->toBeTrue();
});

it('folds the menu as the editor opens unless the person unfolded it there, and leaves every other screen\'s menu as it was', function (): void {
    // The menu, collapsed or expanded, with its width transition over.
    $menuIs = fn (mixed $page, string $state): mixed => cmWaitUntil($page, "document.querySelector('[data-slot=\"sidebar\"]')?.dataset.state === '{$state}' && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");
    $cookies = fn (mixed $page): string => (string) $page->script('() => document.cookie');

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    // Unfolded by hand in the editor: remembered for the editor, and only there.
    $page->click('[data-test="collapse-menu"]');
    $menuIs($page, 'expanded');

    expect($cookies($page))->toContain('editor_sidebar_state=true')
        ->not->toMatch('/(^|;\s*)sidebar_state=/');

    // Back to the list by the editor's own way back: the menu as it was there.
    $page->click('[data-slot="editor-shell"] > header a[href="/admin/posts"]');
    cmWaitUntil($page, "location.pathname === '/admin/posts'");
    $menuIs($page, 'expanded');

    // The editor again: it stays unfolded.
    cmNavigate($page, $this->edit);
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");
    $menuIs($page, 'expanded');

    // Folded by hand in the editor: the next visit folds it again, and the list keeps its own.
    $page->click('[data-test="collapse-menu"]');
    $menuIs($page, 'collapsed');

    expect($cookies($page))->toContain('editor_sidebar_state=false')
        ->not->toMatch('/(^|;\s*)sidebar_state=/');

    $page->navigate('/admin/posts');
    $menuIs($page, 'expanded');

    cmEditorSettled(cmNavigate($page, $this->edit));
    $menuIs($page, 'collapsed');
});

it('gives the menu back as the person left it after going from one editor straight to another', function (): void {
    $other = lifecycleDraft($this->editor, 'Ferry timetable');
    $this->actingAs($this->editor)->get("/admin/content/{$other->id}/edit")->assertOk();
    $menuIs = fn (mixed $page, string $state): mixed => cmWaitUntil($page, "document.querySelector('[data-slot=\"sidebar\"]')?.dataset.state === '{$state}' && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    // From this editor to the other's through the palette: one visit, the editor remounting with the menu still folded.
    $page->click('[data-test="palette-button"]');
    $page->type('[placeholder="Search, go to, or do…"]', 'Ferry timetable');
    cmWaitUntil($page, "[...document.querySelectorAll('[role=\"option\"]')].some((option) => option.textContent.includes('Ferry timetable'))");
    $page->script("() => [...document.querySelectorAll('[role=\"option\"]')].find((option) => option.textContent.includes('Ferry timetable')).click()");
    cmWaitUntil($page, "location.pathname === '/admin/content/{$other->id}/edit' && document.querySelector('.teme-prose') !== null");
    $menuIs($page, 'collapsed');

    // Leaving: the person never folded it, so the list has it unfolded.
    $page->click('[data-slot="editor-shell"] > header a[href="/admin/posts"]');
    cmWaitUntil($page, "location.pathname === '/admin/posts'");
    $menuIs($page, 'expanded');
});
