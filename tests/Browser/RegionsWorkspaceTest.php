<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

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
        ->assertPresent('[data-test="region-fill"]')
        ->assertScript("document.querySelector('[data-test=\"region-fill\"]').textContent.includes('slots used')", true)
        ->assertScript("document.querySelectorAll('[data-test=\"region-card-row\"]').length", 2)
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

it('keeps long region names inside the list, wrapped, clear of the chosen region', function (): void {
    // The good theme, with regions named as a real theme names them.
    // A theme's code is its folder's name, so the copy keeps the name good, in a folder of its own.
    $parent = sys_get_temp_dir().'/teme-long-regions-'.bin2hex(random_bytes(4));
    $folder = $parent.'/good';
    File::copyDirectory(base_path('tests/Fixtures/themes/good'), $folder);
    $manifest = json_decode((string) file_get_contents($folder.'/teme-theme.json'), true);
    $manifest['regions'] = [
        'after-content' => ['label' => 'After the article', 'max' => 5],
        'also-today' => ['label' => 'Front page: also today (three stories beside the cover)', 'max' => 1],
        'header-ad' => ['label' => 'Header banner: ad below the top of every page (728 × 90)', 'max' => 1],
        'side-ad' => ['label' => 'Article side column ad (300 × 600)', 'max' => 1],
        'more' => ['label' => 'Recommended stories on search and 404', 'max' => 1],
    ];
    file_put_contents($folder.'/teme-theme.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('good');

    try {
        $this->actingAs($this->editor)->get('/admin/regions')->assertOk();
        $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions');
        cmWaitUntil($page, "document.querySelectorAll('[data-test=\"region-choice\"]').length === 5");

        $at = cmScriptArray($page, <<<'JS'
            () => {
                const list = document.querySelector('nav[aria-label="Regions"]').getBoundingClientRect();
                const panel = document.querySelector('section[aria-labelledby="chosen-region"]').getBoundingClientRect();
                const choices = [...document.querySelectorAll('[data-test="region-choice"]')];

                return {
                    widest: Math.max(...choices.map((choice) => choice.getBoundingClientRect().right)),
                    listRight: list.right,
                    panelLeft: panel.left,
                    wrapped: choices.some((choice) => choice.getBoundingClientRect().height > 40),
                };
            }
            JS);

        expect($at['widest'])->toBeLessThanOrEqual($at['listRight'] + 0.5)
            ->and($at['listRight'])->toBeLessThanOrEqual($at['panelLeft'])
            ->and($at['wrapped'])->toBeTrue();
    } finally {
        File::deleteDirectory($parent);
    }
});

/**
 * Two regions of the theme `open`, each holding a button, saved and not yet published.
 *
 * @return array{0: Content, 1: Content}
 */
function regionsPair(User $editor): array
{
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open')]]);
    themeActivate('open');

    $sidebar = regionCreate($editor, 'open', 'sidebar');
    regionSave($sidebar, $editor, [regionCardNode('button')]);
    $narrow = regionCreate($editor, 'open', 'narrow');
    regionSave($narrow, $editor, [regionCardNode('button')]);

    return [$sidebar, $narrow];
}

/** The number of requests the page has made for one region's visit since its timings were last cleared. */
function regionRequests(mixed $page, string $key): int
{
    return (int) cmAnswer($page, "performance.getEntriesByType('resource').filter((entry) => entry.name.includes('region={$key}')).length", 'regionRequests');
}

it('fetches a region while the pointer rests on it, so choosing it asks the server for nothing', function (): void {
    regionsPair($this->editor);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=sidebar');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");

    $page->hover('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "performance.getEntriesByType('resource').some((entry) => entry.name.includes('region=narrow'))");
    cmAnswer($page, 'performance.clearResourceTimings()', 'regionRequests');

    $page->click('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-choice\"][data-region=\"narrow\"]').getAttribute('aria-current') === 'page' && document.querySelector('[data-test=\"region-card-row\"]') !== null");
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Narrow'");

    expect(regionRequests($page, 'narrow'))->toBe(0);
    cmNoJavaScriptErrors($page);
});

it('keeps a region already opened, so going back to it asks the server for nothing', function (): void {
    regionsPair($this->editor);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=sidebar');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");

    $page->click('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Narrow'");
    $page->click('[data-test="region-choice"][data-region="sidebar"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Sidebar'");
    cmAnswer($page, 'performance.clearResourceTimings()', 'regionRequests');

    $page->click('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Narrow'");

    expect(regionRequests($page, 'narrow'))->toBe(0);
});

it('shows a region as published when it is chosen again after publishing it', function (): void {
    regionsPair($this->editor);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=sidebar');
    cmWaitUntil($page, "document.querySelector('[data-test=\"region-card-row\"]') !== null");

    // Looked at, so its visit is kept with its changes still unpublished.
    $page->hover('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "performance.getEntriesByType('resource').some((entry) => entry.name.includes('region=narrow'))");

    $page->click('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Narrow' && document.querySelector('[data-test=\"region-unpublished\"]') !== null");
    $page->click('[data-test="region-publish"]');
    cmWaitUntil($page, "document.body.innerText.includes('Published. Readers see it on their next page.')");

    $page->click('[data-test="region-choice"][data-region="sidebar"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Sidebar'");
    $page->click('[data-test="region-choice"][data-region="narrow"]');
    cmWaitUntil($page, "document.querySelector('#chosen-region').innerText === 'Narrow' && document.querySelector('[data-test=\"region-panel\"]') !== null");

    $page->assertSeeIn('#chosen-region', 'Narrow')
        ->assertNotPresent('[data-test="region-unpublished"]');
    expect(cmAnswer($page, "document.querySelector('section[aria-labelledby=\"chosen-region\"]').innerText.includes('Published')", 'regionRequests'))->toBeTrue();
    cmNoJavaScriptErrors($page);
});
