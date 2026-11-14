<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
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

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->harbour = cmItem($this->editor, 'Harbour news');
    $this->market = cmItem($this->editor, 'Market day');
    $this->discussionOf = fn (Content $item): Discussion => Content::query()->findOrFail($item->id)->comment_status;

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
    $this->actingAs($this->editor)->get(route('content.edit', ['content' => $this->harbour->id]))->assertOk();
});

it('sets Discussion from the editor\'s Publish panel at once, its meaning under it, and keeps it after a reload (I-687)', function (): void {
    cmSignIn($this->editor)
        ->navigate("/admin/content/{$this->harbour->id}/edit")
        ->assertSee('Readers see the comments and can add one.')
        ->click('#editor-discussion')
        ->click('[role="option"] >> text="Closed"')
        ->assertSee('Readers see the comments but can’t add one.')
        // The save is its own request; let it land before reading the page again.
        ->waitForEvent('networkidle')
        ->navigate("/admin/content/{$this->harbour->id}/edit")
        ->assertSee('Readers see the comments but can’t add one.');

    expect(($this->discussionOf)($this->harbour))->toBe(Discussion::Closed);
});

it('sets Discussion from Quick edit, its meaning under the select (I-688)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Actions for Harbour news"] [data-action="quick-edit"]')
        // The menu fades out as the sheet slides in; a click before both have
        // settled can land on neither.
        ->assertNotPresent('[role="menu"]')
        ->assertScript("(() => { const sheet = [...document.querySelectorAll('[data-slot=\"sheet-content\"], [data-slot=\"sheet-overlay\"]')]; return sheet.length === 0 ? -1 : sheet.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()", 0)
        ->click("#discussion-{$this->harbour->id}")
        ->click('[role="option"] >> text="Hidden"')
        ->assertSee('Readers see no comments and no comment form.')
        ->press('Save')
        ->assertSee('Saved');

    expect(($this->discussionOf)($this->harbour))->toBe(Discussion::Hidden);
});

it('closes comments on two posts from Bulk edit, and reports each (I-688)', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Select Harbour news"]')
        ->click('[aria-label="Select Market day"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Edit…"')
        ->click('[role="dialog"] [role="combobox"][id$="-discussion"]')
        ->click('[role="option"] >> text="Closed"')
        ->press('Apply to 2')
        ->assertSee('Harbour news: discussion set to Closed')
        ->assertSee('Market day: discussion set to Closed');

    expect(($this->discussionOf)($this->harbour))->toBe(Discussion::Closed)
        ->and(($this->discussionOf)($this->market))->toBe(Discussion::Closed);
});

it('passes axe on Posts with Quick edit open, and with Bulk edit open, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/posts')
        ->click('[aria-label="Actions for Harbour news"] [data-action="quick-edit"]')
        ->assertSee('Readers see the comments and can add one.')
        // The Actions menu fades out as the sheet opens; axe reads a fading item's colours as the page's own.
        ->assertNotPresent('[role="menu"]')
        ->assertNoAccessibilityIssues(3);

    $quick = cmSmallTargets($page);

    $page->click('[role="dialog"] >> text="Cancel"')
        ->assertNotPresent('[role="dialog"]')
        ->click('[aria-label="Select Harbour news"]')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Edit…"')
        ->assertSee('Each change applies to every item listed.')
        ->assertNotPresent('[role="listbox"]')
        // The dialog and its overlay fade in, and axe doesn't retry: it would read their colours mid-fade.
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [role=\"dialog\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);

    $bulk = cmSmallTargets($page);

    expect($quick['small'])->toBe([])
        ->and($quick['checked'])->toBeGreaterThan(0)
        ->and($bulk['small'])->toBe([])
        ->and($bulk['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
