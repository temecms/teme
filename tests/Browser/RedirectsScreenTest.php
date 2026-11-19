<?php

declare(strict_types=1);

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

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->editor)->get(route('tools.redirects.index'))->assertOk();
    $this->actingAs($this->editor)->get(route('tools.redirects.index', ['tab' => 'not-found']))->assertOk();
});

it('passes axe on Tools › Redirects, its drawer and its 404 log, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    $item = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/old-fares', item: $item->id);
    rdSaved('/podcast', 'https://podcasts.example.net/ledger', 302);
    rdSaved('/withdrawn', status: 410, on: false);
    rdLogged(['news/ferry-fares-2025' => 312, 'podcast' => 4], 'www.google.com');
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/tools/redirects')
        ->assertSee('Changed addresses need no redirect.')
        ->assertSee('Another site')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->click('[data-test="redirect-add"]')
        ->assertSee('An address on this site, and where readers who ask for it should go.')
        ->assertNoAccessibilityIssues(3);

    $drawer = cmSmallTargets($page);

    $page->navigate('/admin/tools/redirects?tab=not-found')
        ->assertSee('One row per address.')
        ->assertPresent('[data-test="not-found-redirected"]')
        ->assertNoAccessibilityIssues(3);

    $log = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($drawer['small'])->toBe([])
        ->and($drawer['checked'])->toBeGreaterThan(0)
        ->and($log['small'])->toBe([])
        ->and($log['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('adds a redirect to a post found by its title, and lists it with the post\'s address (I-872)', function (): void {
    $item = addrPublished($this->editor, 'Ferry fares to Bonny Island rise');

    cmSignIn($this->editor)
        ->navigate('/admin/tools/redirects')
        ->click('[data-test="redirect-add"]')
        ->type('#redirect-from', '/old-fares')
        ->type('#redirect-item-search', 'Bonny')
        ->click('[data-test="redirect-item-option"]')
        ->assertSee('Chosen: Ferry fares to Bonny Island rise')
        ->click('[data-test="redirect-save"]')
        ->assertSee('Redirect added. /old-fares now sends readers to “Ferry fares to Bonny Island rise”.')
        ->assertSee('/ferry-fares-to-bonny-island-rise/');

    expect(DB::table('redirects')->where('source', 'old-fares')->value('target'))->toBe($item->id);
});

it('switches a selection off from the bulk bar, Undo switches it back, and deletes a redirect after asking', function (): void {
    rdSaved('/a-old', '/news/');
    rdSaved('/b-old', '/news/');

    cmSignIn($this->editor)
        ->navigate('/admin/tools/redirects')
        ->click('[aria-label="Select all 2 redirects shown"]')
        ->assertSee('2 selected')
        ->click('[role="region"][aria-label="Bulk actions"] >> text="Switch off"')
        ->assertSee('2 redirects switched off. They send nobody on until they’re on again.')
        ->click('Undo')
        ->assertSee('Undone.')
        ->click('[aria-label="More actions for the redirect from /a-old"]')
        ->click('[role="menuitem"] >> text="Delete"')
        ->assertSee('Delete the redirect from /a-old?')
        ->click('[role="dialog"] >> text="Delete redirect"')
        ->assertSee('Redirect deleted. /a-old no longer sends readers on.');

    expect(DB::table('redirects')->pluck('enabled', 'source')->all())->toBe(['b-old' => true]);
});

it('makes a redirect from a logged address, and marks another as gone in one click (I-871)', function (): void {
    rdLogged(['news/ferry-fares-2025' => 312, 'advertise-with-us' => 21]);

    cmSignIn($this->editor)
        ->navigate('/admin/tools/redirects?tab=not-found')
        ->click('[aria-label="Create a redirect from /news/ferry-fares-2025"]')
        ->assertValue('#redirect-from', '/news/ferry-fares-2025')
        ->click('#redirect-kind-address')
        ->type('#redirect-to', '/news/')
        ->click('[data-test="redirect-save"]')
        ->assertSee('Redirect added. /news/ferry-fares-2025 now sends readers to /news/.')
        ->click('[aria-label="Mark /advertise-with-us as gone"]')
        ->assertSee('Marked as gone. /advertise-with-us now answers 410 (gone).');

    expect(DB::table('redirects')->orderBy('source')->pluck('status', 'source')->all())->toBe(['advertise-with-us' => 410, 'news/ferry-fares-2025' => 301]);
});

it('offers a redirect just after a published post is trashed, and the form opens with its address (I-856)', function (): void {
    addrPublished($this->editor, 'Harbour news');

    cmSignIn($this->editor)
        ->navigate('/admin/posts')
        // Trash is in the row's menu (I-3398).
        ->click('[aria-label="More actions for Harbour news"]')
        ->click('[role="menu"] [data-action="trash"]')
        ->assertSee('Moved to the trash. Links to /harbour-news/ no longer reach it.')
        ->click('Add a redirect for its address')
        ->assertValue('#redirect-from', '/harbour-news/')
        ->click('#redirect-kind-address')
        ->type('#redirect-to', '/news/')
        ->click('[data-test="redirect-save"]')
        ->assertSee('Redirect added. /harbour-news now sends readers to /news/.')
        ->assertPathIs('/admin/tools/redirects');
});
