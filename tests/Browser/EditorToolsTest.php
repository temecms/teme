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
