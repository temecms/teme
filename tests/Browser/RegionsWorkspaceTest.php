<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Content › Regions on one screen: a region chosen from the list, its cards
 * added, edited in place, moved and published without opening the editor.
 */
beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($this->editor)->get('/admin/regions')->assertOk();
});

it('fills a region, adds a callout and edits it in place, moves it, saves a draft and publishes', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions');

    $page->click('[data-test="region-create"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-panel\"]') !== null");

    // Text first, then a callout, each filled in its own dialog.
    $page->click('[data-test="region-add-card"]')
        ->click('[data-test="add-card-dialog"] [data-card="paragraph"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-editor\"] .ProseMirror') !== null");
    $page->type('[data-test="region-card-editor"] .ProseMirror', 'Harbour');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-editor\"] .ProseMirror').innerText.includes('Harbour')");
    $page->click('[data-test="edit-card-done"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"edit-card-dialog\"]') === null");

    $page->click('[data-test="region-add-card"]')
        ->click('[data-test="add-card-dialog"] [data-card="callout"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-editor\"] [data-node-view-wrapper]') !== null");
    cmNoJavaScriptErrors($page);
    $page->type('[data-test="region-card-editor"] [aria-label="Callout text"]', 'Ferries run late on Fridays');
    $page->click('[data-test="edit-card-done"]');
    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"region-card-row\"]').length === 2 && document.body.innerText.includes('Ferries run late on Fridays')");

    // The callout to the top.
    $page->click('[data-test="region-card-row"][data-block="callout"] button[aria-label^="Move"][aria-label$="up"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]').dataset.block === 'callout'")
        ->assertSeeIn('[data-test="region-fill"]', '2 of 5 blocks used')
        ->assertSeeIn('[data-test="region-unpublished"]', 'Changes not published yet');

    $page->click('[data-test="region-save-draft"]');
    cmWaitUntil($page, "document.body.innerText.includes('Draft saved.')");
    $page->click('[data-test="region-publish"]');
    cmWaitUntil($page, "document.body.innerText.includes('Published. Readers see it on their next page.')")
        ->assertNotPresent('[data-test="region-unpublished"]');
    cmNoJavaScriptErrors($page);

    $region = Content::query()->where('type', 'region')->firstOrFail();

    expect($region->status)->toBe('published')
        ->and(array_column($region->publishedRevision?->document['content'] ?? [], 'type'))->toBe(['callout', 'paragraph'])
        ->and(json_encode($region->publishedRevision?->document))->toContain('Harbour');
});

it('opens each card’s own form in place', function (string $card): void {
    $region = regionCreate($this->editor, 'default');
    regionSave($region, $this->editor, [regionCardNode($card)]);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=after-content');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");
    $page->click('[data-test="region-card-row"] button:has-text("Edit")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-editor\"] [data-node-view-wrapper]') !== null");
    cmNoJavaScriptErrors($page);
    $page->click('[data-test="edit-card-done"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"edit-card-dialog\"]') === null && document.querySelectorAll('[data-test=\"region-card-row\"]').length === 1");
    cmNoJavaScriptErrors($page);
})->with(['callout', 'button', 'image', 'gallery', 'video', 'audio', 'embed', 'snippet', 'faq', 'steps', 'featured-posts']);

it('discards a region never published back to empty', function (): void {
    $region = regionCreate($this->editor, 'default');
    regionSave($region, $this->editor, [regionCardNode('callout')]);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=after-content');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");
    $page->click('button:has-text("Discard changes")')
        ->click('[data-test="region-discard-confirm"]');
    cmWaitUntil($page, "document.body.innerText.includes('Changes discarded.') && document.querySelector('[data-test=\"region-card-row\"]') === null");

    expect(Content::query()->findOrFail($region->id)->workingRevision?->document['content'] ?? [])->toBe([]);
});

it('stacks a card row’s buttons under its name in a narrow panel, and passes axe', function (): void {
    $region = regionCreate($this->editor, 'default');
    regionSave($region, $this->editor, [regionCardNode('callout'), docText('Hello')]);

    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), '/admin/regions?region=after-content');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");

    $sideways = $page->script(<<<'JS'
        () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)
        JS);

    expect($sideways)->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});
