<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = fn (Content $item): string => "/admin/content/{$item->id}/edit";

    // Waits until the header's chip reads as $text: "Status: " for screen readers, then the words.
    $this->chipIs = fn (mixed $page, string $text): mixed => cmWaitUntil($page, "document.querySelector('header [data-test=\"status-chip\"]')?.textContent === ".json_encode($text, JSON_THROW_ON_ERROR));

    // Waits for words on the page, or within one element (issue #83).
    $this->textShown = fn (mixed $page, string $text, string $within = 'body'): mixed => cmWaitUntil($page, '(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).")?.innerText ?? '').includes(".json_encode($text, JSON_THROW_ON_ERROR).')');

    // Waits until what was typed last is saved: first until the save state's hook has noticed the change, then until it says saved, with a time.
    $this->settled = function (mixed $page): mixed {
        $state = "document.querySelector('[data-test=\"save-state\"]')";

        cmWaitUntil($page, "{$state}?.dataset.state === 'saving'");

        return cmWaitUntil($page, "{$state}?.dataset.state === 'saved' && {$state}?.dataset.savedAt !== undefined");
    };

    // Waits until a select's list has closed, then until a dialog is open and its animations are over, or gone.
    $this->listboxGone = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");
    $this->dialogOpen = fn (mixed $page): mixed => cmWaitUntil($page, "{$this->arrived} === 0");
    $this->dialogGone = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null");

    // The plain text of an item's working revision.
    $this->working = fn (Content $item): string => (string) ContentRevision::query()->whereKey(Content::query()->whereKey($item->id)->value('working_revision_id'))->value('plain_text');

    // Waits until an open dialog has finished arriving: its content's and overlay's own animations (issue #60).
    $this->arrived = "(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()";

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(($this->edit)($this->draft))->assertOk();
});

it('shows the item\'s state as a chip beside the way back, and says a live item has unpublished changes as soon as it is typed into (I-1316)', function (): void {
    $live = addrPublished($this->editor, 'Ferry fares');
    $this->actingAs($this->editor)->get(($this->edit)($live))->assertOk();

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    ($this->chipIs)($page, 'Status: Draft');
    ($this->textShown)($page, 'Saved', '[data-test="save-state"]');

    cmEditorSettled($page->navigate(($this->edit)($live)));
    ($this->chipIs)($page, 'Status: Published');

    $page->type('#editor-title', 'Ferry fares rise');

    ($this->chipIs)($page, 'Status: Published, with unpublished changes');
    ($this->settled)($page);

    $page->click('[aria-label="Post settings"] button:text-is("Update")');

    ($this->textShown)($page, 'Published.');
    ($this->chipIs)($page, 'Status: Published');
});

it('opens Share as a pane, makes a preview link of what was just typed, and revokes it (I-1316)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->type('.teme-prose', 'The ferry leaves at dawn.')
        ->click('[aria-label="Share"]');

    ($this->textShown)($page, 'Making one saves your changes first.', '[aria-label="Post settings"]');

    $page->click('[data-test="share-make"]');

    cmWaitUntil($page, "document.querySelector('#share-url')?.value.includes('/preview/') ?? false");
    ($this->textShown)($page, 'Made by Eze Editor', '[data-test="share-links"]');

    $link = DB::table('preview_links')->where('content_id', $this->draft->id)->sole();

    expect((string) ContentRevision::query()->whereKey($link->revision_id)->value('plain_text'))->toContain('The ferry leaves at dawn.');

    $page->click('[data-test="share-links"] button:has-text("Revoke")');
    ($this->textShown)($page, "Anyone who has it can no longer open the preview. This can't be undone.");
    $page->assertSee('Keep it')->assertSee('Revoke link');
    $page->click('[data-test="share-revoke-confirm"]');

    ($this->textShown)($page, 'Revoked', '[data-test="share-links"]');
});

it('opens Preview in a tab of its own after saving what was typed, pointed at the latest saved version (I-1316)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // Stands in for the new tab, so the test can read where it was sent and what it was cut from.
    $page->script("() => { window.previewTab = { opener: window, location: { href: '' }, close() {} }; window.open = (url, name) => { window.previewName = name; return window.previewTab; }; }");

    $page->type('.teme-prose', 'The ferry leaves at dawn.')
        ->click('[data-test="editor-preview"]');

    cmWaitUntil($page, 'window.previewTab.location.href === '.json_encode(route('content.preview', $this->draft->id, false), JSON_THROW_ON_ERROR));
    cmWaitUntil($page, 'window.previewTab.opener === null');
    cmWaitUntil($page, 'window.previewName === '.json_encode('teme-preview-'.$this->draft->id, JSON_THROW_ON_ERROR));

    expect(($this->working)($this->draft))->toContain('The ferry leaves at dawn.');
});

it('schedules a draft at a set time on the site\'s clock, confirmed first, and says when it publishes (I-1316, I-840)', function (): void {
    cmSettings(['timezone' => 'Pacific/Auckland'], 'general');
    $tomorrow = CarbonImmutable::now('Pacific/Auckland')->addDay()->setTime(9, 0);

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('#editor-when')
        ->click('[role="option"] >> text="At a set time…"');

    ($this->listboxGone)($page);

    $page->click('button:text-is("Tomorrow morning")');

    ($this->textShown)($page, '(Pacific/Auckland)');

    $page->click('[aria-label="Post settings"] button:text-is("Schedule")');

    ($this->textShown)($page, 'Schedule this post?');
    ($this->dialogOpen)($page);

    $page->click('[role="dialog"] button:text-is("Schedule")');

    ($this->dialogGone)($page);
    ($this->textShown)($page, 'Scheduled for', '[data-test="publish-when"]');
    ($this->chipIs)($page, 'Status: Scheduled');

    $runAt = DB::table('scheduled_publications')->where('content_id', $this->draft->id)->value('run_at');

    expect(CarbonImmutable::parse((string) $runAt)->equalTo($tomorrow))->toBeTrue();
});

it('refuses a time that has already passed, beside the field, and sends nothing (I-1316)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('#editor-when')
        ->click('[role="option"] >> text="At a set time…"');

    ($this->listboxGone)($page);

    $page->type('#editor-when-at', '2020-01-06T09:00')
        ->click('[aria-label="Post settings"] button:text-is("Schedule")');

    ($this->textShown)($page, 'Choose a time in the future.');
    cmWaitUntil($page, "document.activeElement?.id === 'editor-when-at'");
    ($this->dialogGone)($page);

    expect(DB::table('scheduled_publications')->where('content_id', $this->draft->id)->count())->toBe(0);
});

it('schedules a live item\'s update from the split button\'s choices, at once, with no confirmation (I-840)', function (): void {
    $live = addrPublished($this->editor, 'Ferry fares');
    $this->actingAs($this->editor)->get(($this->edit)($live))->assertOk();

    // A live item says only when it was published until an update is being scheduled.
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($live)))
        ->assertPresent('[data-test="published-at"]')
        ->assertNotPresent('#editor-when')
        ->click('[aria-label="More publishing choices"]')
        ->click('[role="menuitem"] >> text="Schedule the update…"');

    cmWaitUntil($page, "document.querySelector('#editor-when-at') !== null");

    $page->click('button:text-is("Next Monday")')
        ->click('[aria-label="Post settings"] button:text-is("Schedule update")');

    ($this->textShown)($page, 'Scheduled for', '[data-test="publish-when"]');
    ($this->chipIs)($page, 'Status: Published, with an update scheduled');
    ($this->dialogGone)($page);

    expect(DB::table('scheduled_publications')->where('content_id', $live->id)->value('action'))->toBe('update');
});

it('tells a contributor when the post an editor scheduled publishes, with nothing to move it (I-1316)', function (): void {
    $chidi = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    $own = lifecycleDraft($chidi, 'Night market');
    schedFor($own, $this->editor, now()->addDay()->startOfMinute()->toIso8601String());
    $this->actingAs($chidi)->get(($this->edit)($own))->assertOk();

    $page = cmEditorSettled(cmSignIn($chidi)->navigate(($this->edit)($own)));

    ($this->textShown)($page, 'Scheduled for', '[data-test="publish-when"]');

    $page->assertDontSeeIn('[data-test="publish-when"]', 'After review')
        ->assertNotPresent('[data-test="publish-when"] button');
});

it('lets an editor credit someone else, saved at once, and shows an author only the name (I-1316)', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Okafor'])->assignRole('author');
    $own = lifecycleDraft($ada, 'Market day');
    $this->actingAs($ada)->get(($this->edit)($own))->assertOk();

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('#editor-author')
        ->click('[role="option"] >> text="Ada Okafor"');

    ($this->listboxGone)($page);
    ($this->textShown)($page, 'Ada Okafor', '#editor-author');

    expect((int) Content::query()->findOrFail($this->draft->id)->author_id)->toBe($ada->id);

    $page = cmEditorSettled(cmSignIn($ada)->navigate(($this->edit)($own)));

    ($this->textShown)($page, 'Ada Okafor', '[aria-label="Post settings"]');

    $page->assertNotPresent('#editor-author');
});

it('shows the featured image as a compact strip, its alt text and credit under Edit, saying "no alt text" until alt text is written (I-237)', function (): void {
    $image = pageImage($this->editor, 800, 600);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($image->id, (string) $this->editor->id, $image->title, null, null, 'Photo: Ada Okafor', licence: 'CC BY 4.0'));
    $post = headPost($this->editor, 'Quay life', featured: $image->refresh());
    $this->actingAs($this->editor)->get(($this->edit)($post))->assertOk();

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($post)));

    ($this->textShown)($page, 'Featured image · 800 × 600 · no alt text', '[data-test="featured-strip"]');
    $page->assertScript("[...document.querySelectorAll('[data-test=\"featured-strip\"] button')].map((button) => button.textContent.trim()).join('|')", 'Replace|Edit|Remove')
        ->click('#editor-featured-edit');

    ($this->textShown)($page, 'Photo: Ada Okafor · CC BY 4.0', '[data-test="featured-credit"]');
    ($this->textShown)($page, 'Alt text for this post');
    ($this->textShown)($page, 'The library has no alt text for this image, so write one here.');

    $page->type('#editor-featured-alt', 'Boats at the quay at dawn.');

    cmWaitUntil($page, "document.querySelector('[data-test=\"alt-missing\"]') === null");
    ($this->textShown)($page, 'Featured image · 800 × 600 · alt text set', '[data-test="featured-strip"]');
});

it('passes axe with a time being set and with the Share pane open, in the light and dark schemes, every target 24 pixels or more', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('#editor-when')
        ->click('[role="option"] >> text="At a set time…"');

    ($this->listboxGone)($page);
    cmWaitUntil($page, "document.querySelector('#editor-when-at') !== null");

    $page->assertNoAccessibilityIssues(3);

    $setting = cmSmallTargets($page);

    $page->click('[aria-label="Share"]');

    ($this->textShown)($page, 'Make a preview link', '[aria-label="Post settings"]');

    $page->assertNoAccessibilityIssues(3);

    $sharing = cmSmallTargets($page);

    expect($setting['small'])->toBe([])
        ->and($setting['checked'])->toBeGreaterThan(0)
        ->and($sharing['small'])->toBe([])
        ->and($sharing['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
