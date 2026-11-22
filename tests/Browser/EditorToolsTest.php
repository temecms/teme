<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The rail's panes (docs/architecture/design-system.md, 4.39): a head with
// Back, the title and Close, "Open larger" with the pane's state kept, the
// card inspector in place of the sections, and the action bar in view.

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (TwoEditorsTest); History's loads are counted.
    $this->historyLoads = 0;
    Event::listen(RequestHandled::class, function (RequestHandled $event): void {
        if ($event->request->isMethod('GET') && str_ends_with($event->request->path(), '/revisions')) {
            $this->historyLoads++;
        }

        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // A paragraph, an image card and a paragraph, saved twice so History has rows.
    $draft = lifecycleDraft($this->editor, 'Harbour news');
    $draft = docSave($draft, $this->editor, docWith(docText('The ferry leaves at nine.')));
    $this->draft = docSave($draft, $this->editor, docWith(
        docText('The ferry leaves at nine.'),
        pageImageNode(pageImage($this->editor, 1600, 900), (string) Str::ulid()),
        docText('Fares rise on Monday.'),
    ));
    $this->edit = "/admin/content/{$this->draft->id}/edit";

    // The rail, its pane's head, and its sections' sign: the action bar's Preview.
    $this->etlRail = 'aside[aria-label="Post settings"]';
    $this->etlHead = '[data-slot="pane-head"]';
    $this->etlSections = "document.querySelector('aside [data-test=\"editor-preview\"]') !== null";
    $this->etlFocused = "(document.activeElement?.getAttribute('aria-label') ?? document.activeElement?.textContent ?? '')";

    // The bar's tools in order, by their names, Tools last; and Tools' rows by their names.
    $this->etlBar = "Array.from(document.querySelectorAll('header [data-slot=\"editor-tools\"] [role=\"group\"]:not([aria-label=\"View\"]) button'), (button) => button.getAttribute('aria-label') ?? button.textContent.trim().split(' ')[0])";
    $this->etlRows = "Array.from(document.querySelectorAll('[data-slot=\"tool-row\"] [data-slot=\"tool-name\"]'), (button) => button.textContent.trim())";
    $this->etlTools = 'header [data-test="editor-tools"]';

    // Opens Tools and waits for its search to take focus.
    $this->etlOpenTools = function (mixed $page): void {
        $page->click($this->etlTools);
        cmWaitUntil($page, "document.activeElement?.getAttribute('type') === 'search' && document.activeElement.closest('[role=\"dialog\"]') !== null");
    };

    // Waits until a pin's save is in: the list is busy while it is on its way.
    $this->etlPinSaved = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[data-slot=\"tool-row\"]')?.closest('[aria-busy]')?.getAttribute('aria-busy') === 'false'");

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('opens History as a pane with Back, its title focused, and Close', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->click('header button[aria-label="History"]');
    cmWaitUntil($page, "document.activeElement?.tagName === 'H2' && document.activeElement.textContent === 'History' && document.activeElement.closest('[data-slot=\"pane-head\"]') !== null");

    $page->assertPresent("{$this->etlRail} {$this->etlHead} button[aria-label=\"Close History\"]")
        ->click("{$this->etlRail} {$this->etlHead} button[aria-label=\"Back to the post settings\"]");

    cmWaitUntil($page, "{$this->etlSections} && document.querySelector('{$this->etlHead}') === null");
    cmWaitUntil($page, "document.activeElement?.getAttribute('aria-label') === 'History' && document.activeElement.closest('header') !== null");
});

it('opens History larger and back, keeping where it was', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->click('header button[aria-label="History"]');
    cmWaitUntil($page, "document.querySelector('{$this->etlRail} ol li') !== null");
    $loads = $this->historyLoads;

    // Marks the list as it is drawn now: the same node must travel.
    $page->script("document.querySelector('{$this->etlRail} ol').dataset.marked = 'yes'");
    $page->click("{$this->etlRail} {$this->etlHead} button:text-is(\"Open larger\")");

    cmWaitUntil($page, "(() => { const dialog = document.querySelector('[role=\"dialog\"]'); return dialog !== null && dialog.querySelector('ol[data-marked=\"yes\"]') !== null && document.querySelector('{$this->etlRail} ol') === null; })()");

    $page->click('[role="dialog"] button:text-is("Back to the pane")');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null && document.querySelector('{$this->etlRail} ol[data-marked=\"yes\"]') !== null");
    cmWaitUntil($page, "document.activeElement?.textContent.trim() === 'Open larger'");

    expect($this->historyLoads)->toBe($loads);
});

it('replaces the sections with a selected card\'s settings, and gives them back when it is deselected', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->click('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, "document.querySelector('{$this->etlRail} {$this->etlHead} h2')?.textContent === 'Image' && !({$this->etlSections})");
    $page->assertPresent("{$this->etlRail} {$this->etlHead} button[aria-label=\"Back to the post settings\"]");

    $page->click('.teme-prose > p:last-child');
    cmWaitUntil($page, "{$this->etlSections} && document.querySelector('{$this->etlHead}') === null");
});

it('keeps the Publish section open and the action bar in view while the rail scrolls', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 600), $this->edit));

    // Publish has no fold control; the sections after it have.
    $page->assertScript("document.querySelector('{$this->etlRail} [data-test=\"publish-section\"]') !== null && document.querySelector('{$this->etlRail} [data-test=\"publish-section\"] > header [aria-expanded]') === null")
        ->assertScript("document.querySelectorAll('{$this->etlRail} [data-slot=\"rail-section\"] button[aria-expanded]').length > 0");

    $page->script("(() => { const area = document.querySelector('{$this->etlRail} [data-slot=\"scroll-area\"]'); area.scrollTop = area.scrollHeight; })()");
    cmWaitUntil($page, "document.querySelector('{$this->etlRail} [data-slot=\"scroll-area\"]').scrollTop > 0");

    $page->assertScript("(() => { const bar = document.querySelector('{$this->etlRail} [data-test=\"editor-preview\"]').closest('[data-slot=\"action-bar\"]'); const area = document.querySelector('{$this->etlRail} [data-slot=\"scroll-area\"]'); return Math.abs(bar.getBoundingClientRect().top - area.getBoundingClientRect().top) < 2; })()");
});

it('shows Outline and History, then Assistant and Share pinned, then Tools', function (): void {
    aiReady();
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    cmWaitUntil($page, "{$this->etlBar}.join() === 'Outline,History,Assistant,Share,Tools'");
});

it('unpins Share from Tools and it stays unpinned on the next visit', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    ($this->etlOpenTools)($page);

    $page->click('button[aria-label="Unpin Share"]');
    cmWaitUntil($page, "document.querySelector('button[aria-label=\"Pin Share to the bar\"]')?.getAttribute('aria-pressed') === 'false' && !{$this->etlBar}.includes('Share')");
    ($this->etlPinSaved)($page);

    $page = cmEditorSettled(cmNavigate($page, $this->edit));
    cmWaitUntil($page, "{$this->etlBar}.join() === 'Outline,History,Tools'");

    expect($this->editor->refresh()->preferences['editor.pinned_tools'] ?? null)->toBe(['assistant']);
});

it('pins Share again from Tools and it returns to the bar, after Assistant', function (): void {
    aiReady();
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant']]);
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    cmWaitUntil($page, "{$this->etlBar}.join() === 'Outline,History,Assistant,Tools'");
    ($this->etlOpenTools)($page);

    $page->click('button[aria-label="Pin Share to the bar"]');
    cmWaitUntil($page, "{$this->etlBar}.join() === 'Outline,History,Assistant,Share,Tools'");
    ($this->etlPinSaved)($page);

    expect($this->editor->refresh()->preferences['editor.pinned_tools'] ?? null)->toBe(['assistant', 'share']);
});

it('finds a tool by typing in Tools, and opens it with Enter', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    ($this->etlOpenTools)($page);

    $page->page()->locator(':focus')->type('hist');
    cmWaitUntil($page, "{$this->etlRows}.join() === 'History'");
    $page->page()->locator(':focus')->press('Enter');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"tool-row\"]') === null && document.activeElement?.tagName === 'H2' && document.activeElement.textContent === 'History' && document.activeElement.closest('{$this->etlHead}') !== null");
});

it('names each group of Tools by its heading', function (): void {
    aiReady();
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    ($this->etlOpenTools)($page);

    // Each group's aria-labelledby names one element, the group's own heading.
    expect(cmScriptArray($page, "Array.from(new Set(Array.from(document.querySelectorAll('[data-slot=\"tool-row\"]'), (row) => row.closest('section'))), (group) => { const ids = (group.getAttribute('aria-labelledby') ?? '').split(/\\s+/).filter(Boolean); const heading = ids.length === 1 ? document.getElementById(ids[0]) : null; return heading !== null && heading.tagName === 'H3' && group.contains(heading) ? heading.textContent : null; })"))
        ->toBe(['Pinned to the bar', 'Teme']);
});

it('opens every tool from the command palette too', function (): void {
    aiReady();
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->click('[data-test="palette-button"]');
    $page->type('[placeholder="Search, go to, or do…"]', 'Open');
    cmWaitUntil($page, "(() => { const shown = Array.from(document.querySelectorAll('[role=\"option\"]'), (option) => option.textContent.toLowerCase()).join(' '); return ['outline', 'history', 'share', 'assistant'].every((name) => shown.includes(name)); })()");
});

it('keeps the Tools menu within a phone screen at 375px', function (): void {
    // On a phone the menu is a drawer, so cmEditorSettled's folded sidebar never comes.
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), $this->edit);
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");
    $page->click('[data-test="editor-tools"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="popover-content"]\') !== null');

    $box = $page->script(<<<'JS'
        () => {
            const menu = document.querySelector('[data-slot="popover-content"]');
            if (menu === null) {
                return null;
            }

            const rect = menu.getBoundingClientRect();

            return {
                width: Math.round(rect.width),
                left: Math.round(rect.left),
                right: Math.round(rect.right),
                viewport: window.innerWidth,
            };
        }
        JS);

    expect($box)->not->toBeNull()
        ->and($box['width'])->toBeLessThanOrEqual($box['viewport'] - 16)
        ->and($box['left'])->toBeGreaterThanOrEqual(0)
        ->and($box['right'])->toBeLessThanOrEqual($box['viewport']);
});

it('passes axe with Tools open, light and dark', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    ($this->etlOpenTools)($page);

    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([]);
})->with(['light', 'dark']);
