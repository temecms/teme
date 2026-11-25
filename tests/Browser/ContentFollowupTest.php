<?php

/**
 * Lane 1.6 content follow-up: date cells, Comments-off title, navigation
 * Open in new tab, regions chrome, media field order and comments search.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

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

it('draws regions with used/max, Publish region, and Edit without a pencil', function (): void {
    $region = regionCreate($this->editor, 'default');
    regionSave($region, $this->editor, [regionCardNode('callout')]);

    $this->actingAs($this->editor)->get('/admin/regions?region=after-content')->assertOk();

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/regions?region=after-content');

    cmWaitUntil($page, "document.querySelector('[data-test=\"region-choice\"]') !== null")
        ->assertScript("[...document.querySelectorAll('[data-test=\"region-choice\"]')].some((row) => /\\d+\\/\\d+/.test(row.textContent))", true)
        ->assertSee('active theme')
        ->assertSeeIn('[data-test="region-publish"]', 'Publish region')
        ->assertScript("document.querySelector('[data-test=\"region-publish\"]')?.disabled === false", true)
        ->assertScript("(() => { const edit = [...document.querySelectorAll('[data-test=\"region-card-row\"] button')].find((b) => b.textContent.trim() === 'Edit'); return edit !== undefined && edit.querySelector('svg') === null; })()", true)
        ->assertSee('Remove');

    $page->click('[data-test="region-publish"]');
    cmWaitUntil($page, "document.body.innerText.includes('Published. Readers see it on their next page.')")
        ->assertScript("document.querySelector('[data-test=\"region-publish\"]')?.disabled === true", true);
    cmNoJavaScriptErrors($page);
});

it('orders the media library’s primary fields before Title, with Edit image not primary', function (): void {
    $harbour = mediaUpload($this->editor, mediaJpeg(1200, 800), 'harbour.jpg')->refresh();
    $this->actingAs($this->editor)->get('/admin/media')->assertOk();

    $page = cmSignIn($this->editor)->resize(1280, 900);
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
