<?php

declare(strict_types=1);

use App\Domain\Menus\Models\NavigationItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    Storage::fake('media');
    Storage::fake('media-originals');

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
});

it('shows a scheduled row’s day above Scheduled in the Date column', function (): void {
    $when = now()->addDays(5)->startOfHour();
    $post = typesDraft($this->editor, 'post', 'Ferry timetable');
    docSave($post, $this->editor, docWith(docText('Ready.')));
    schedFor($post, $this->editor, $when->toIso8601String());

    $this->actingAs($this->editor)->get('/admin/posts?tab=scheduled')->assertOk();

    $page = cmSignIn($this->editor)->navigate('/admin/posts?tab=scheduled');
    $day = $when->timezone(config('app.timezone'))->format('j M Y');

    cmWaitUntil($page, "document.querySelector('[data-test=\"row-title\"]') !== null")
        ->assertScript(<<<JS
            (() => {
                const cell = [...document.querySelectorAll('td')].find((td) => td.textContent.includes('Scheduled') && td.querySelector('[title]'));
                if (!cell) return 'no-cell';
                const text = cell.querySelector('[title]')?.closest('span')?.innerText ?? cell.innerText;
                if (!text.includes('Scheduled')) return 'no-scheduled:' + text;
                if (text.indexOf('Scheduled') < 3) return 'order:' + text;
                return text.includes('{$day}') || /\\d{1,2} \\w{3} \\d{4}/.test(text) ? 'ok' : 'day:' + text;
            })()
        JS, 'ok');
    cmNoJavaScriptErrors($page);
});

it('names the Comments screen Comments are switched off as its visible title', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $page = cmSignIn($this->editor)->navigate('/admin/comments');

    cmWaitUntil($page, "document.querySelector('h1') !== null")
        ->assertScript("document.querySelector('h1')?.textContent.trim()", 'Comments are switched off');
    cmNoJavaScriptErrors($page);
});

it('saves Open in new tab from an item’s Details on the navigation editor', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $about = addrPublished($this->editor, 'About', 'page');
    $menu = navCreate($this->editor, 'Main');
    navSave($this->editor, $menu, [
        ['kind' => 'content', 'target_id' => $about->id],
    ]);

    $this->actingAs($this->editor)->get("/admin/navigation/{$menu->id}")->assertOk();

    $page = cmSignIn($this->editor)->navigate("/admin/navigation/{$menu->id}");

    cmWaitUntil($page, "document.querySelector('[data-control=\"details\"]') !== null")
        ->click('[data-control="details"]');
    cmWaitUntil($page, "document.querySelector('[data-control=\"new-tab\"]') !== null")
        ->click('[data-control="new-tab"]')
        ->press('Save menu');
    cmWaitUntil($page, "document.body.innerText.includes('Saved')");

    expect(NavigationItem::query()->where('opens_new_tab', true)->exists())->toBeTrue();
    cmNoJavaScriptErrors($page);
});

it('draws regions at 1440 with slots in the head, short description, tabs and axe', function (): void {
    $region = regionCreate($this->editor, 'default');
    regionSave($region, $this->editor, [regionCardNode('callout')]);

    $this->actingAs($this->editor)->get('/admin/regions?region=after-content')->assertOk();

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/regions?region=after-content');

    cmWaitUntil($page, "document.querySelector('[data-test=\"region-choice\"]') !== null")
        ->assertSee('Your own content in the spots')
        ->assertSee('Each region takes only the cards its theme allows.')
        ->assertDontSee('switch themes and you fill that theme')
        ->assertScript("[...document.querySelectorAll('[data-test=\"region-choice\"]')].some((row) => /\\d+\\/\\d+/.test(row.textContent))", true)
        ->assertSeeIn('[data-test="region-fill"]', '1 of 5 slot used')
        ->assertSee('active theme')
        ->assertScript("document.querySelector('[data-slot=\"tabs-list\"]')?.getAttribute('data-variant') === 'underline' || [...document.querySelectorAll('[role=\"tablist\"]')].some((list) => list.className.includes('underline') || getComputedStyle(list).borderBottomStyle !== 'none')", true)
        ->assertSeeIn('[data-test="region-publish"]', 'Publish region')
        ->assertScript("(() => { const edit = [...document.querySelectorAll('[data-test=\"region-card-row\"] button')].find((b) => b.textContent.trim() === 'Edit'); return edit !== undefined && edit.querySelector('svg') === null; })()", true)
        ->assertSee('Remove')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);
    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
    cmNoJavaScriptErrors($page);
});

it('orders the media library’s fields and Edit image at 1440 with axe', function (): void {
    $harbour = mediaUpload($this->editor, mediaJpeg(1200, 800), 'harbour.jpg')->refresh();
    $this->actingAs($this->editor)->get('/admin/media')->assertOk();

    $page = cmSignIn($this->editor)->resize(1440, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('#attachment-{$harbour->id}-alt') !== null");

    $order = $page->script(<<<JS
        (() => {
            const ids = [...document.querySelectorAll('[id^="attachment-{$harbour->id}-"]')]
                .map((el) => el.id.replace('attachment-{$harbour->id}-', ''));
            return ids.filter((id) => ['alt', 'caption', 'credit', 'title', 'licence'].includes(id)).join('|');
        })()
    JS);

    expect($order)->toBe('alt|caption|credit|title|licence')
        ->and($page->script("document.querySelector('[data-test=\"media-edit-image\"]')?.className.includes('bg-surface-3')"))->toBeTrue();

    $page->assertNoAccessibilityIssues(3);
    $targets = cmSmallTargets($page);
    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
    cmNoJavaScriptErrors($page);
});

it('keeps Title first in the media picker’s details', function (): void {
    $dunes = mediaUpload($this->editor, mediaJpeg(1000, 700), 'dunes.jpg')->refresh();
    $this->actingAs($this->editor)->get('/admin/_catalogue')->assertOk();

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/_catalogue#media-picker');
    cmWaitUntil($page, "document.querySelector('[data-test=\"catalogue-picker-featured\"]') !== null")
        ->click('[data-test="catalogue-picker-featured"]');
    shellSettled($page, '[data-slot="dialog-content"]');
    cmWaitUntil($page, "document.querySelectorAll('[role=\"dialog\"] [role=\"option\"]').length >= 1")
        ->click('[role="dialog"] [role="option"][data-media-id="'.$dunes->id.'"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-details\"] #attachment-{$dunes->id}-title') !== null");

    $order = $page->script(<<<JS
        (() => {
            const panel = document.querySelector('[data-test="media-modal-details"]');
            const ids = [...panel.querySelectorAll('[id^="attachment-{$dunes->id}-"]')]
                .map((el) => el.id.replace('attachment-{$dunes->id}-', ''));
            return ids.filter((id) => ['title', 'alt', 'caption', 'credit', 'licence'].includes(id)).join('|');
        })()
    JS);

    expect($order)->toStartWith('title|');
    $page->assertNoAccessibilityIssues(3);
    cmNoJavaScriptErrors($page);
});

it('shows a thumbnail mark on every custom-type list row', function (): void {
    ctCreate($this->admin, ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes']);
    typesDraft($this->editor, 'note', 'Harbour note');

    $this->actingAs($this->editor)->get('/admin/notes')->assertOk();

    $page = cmSignIn($this->editor)->resize(1440, 900)->navigate('/admin/notes');

    cmWaitUntil($page, "document.querySelector('[data-test=\"row-title\"]') !== null")
        ->assertSee('Everything of this kind on the site.')
        ->assertScript("document.querySelectorAll('[data-test=\"featured-thumb\"], [data-test=\"featured-none\"]').length >= 1", true)
        ->assertScript("[...document.querySelectorAll('tbody tr')].every((row) => row.querySelector('[data-test^=\"featured-\"]') !== null)", true)
        ->assertNoAccessibilityIssues(3);
    cmNoJavaScriptErrors($page);
});

it('puts a magnifier on Comments search', function (): void {
    $item = cmItem($this->editor, 'Harbour news');
    cmNext($item, attributes: ['author_name' => 'Bola Ade']);
    $this->actingAs($this->editor)->get(route('comments.index'))->assertOk();

    $page = cmSignIn($this->editor)->navigate('/admin/comments?view=all');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"search-field\"]') !== null")
        ->assertScript("document.querySelector('[data-slot=\"search-field\"] svg') !== null", true)
        ->assertPresent('[data-test="list-search"]');
    cmNoJavaScriptErrors($page);
});
