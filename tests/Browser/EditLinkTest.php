<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // One application serves every request in the test's process: each one
    // starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->site = dtSite($this->editor);
});

it('gives a signed-in editor core\'s Edit link on an article: second in the tab order, clear of the page\'s end, and passing axe in light and dark', function (): void {
    $article = dtUrl($this->site, 'longRead');

    $page = visit('/admin/login')
        ->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin')
        ->navigate($article)
        ->assertSee('Edit post')
        ->assertAttribute('a.teme-edit-link', 'href', route('content.edit', ['content' => $this->site['longRead']->id]))
        ->assertNoAccessibilityIssues(3);

    // The skip link, then the Edit link.
    $page->keys('.teme-skip-link', 'Tab')
        ->assertScript('document.activeElement.textContent', 'Edit post');

    // At a phone's width it keeps clear of the edges, and its room keeps it off the footer at the page's end.
    $page->resize(375, 812)
        ->assertScript("(() => { const link = document.querySelector('.teme-edit-link').getBoundingClientRect(); return link.right <= innerWidth - 16 && link.bottom <= innerHeight - 16; })()")
        ->assertScript("(() => { scrollTo(0, document.documentElement.scrollHeight); return document.querySelector('.site-footer').getBoundingClientRect().bottom <= document.querySelector('.teme-edit-link').getBoundingClientRect().top; })()");

    designPublish(['theme.default' => ['color_scheme' => 'dark']]);

    $page->navigate($article)->assertSee('Edit post')->assertNoAccessibilityIssues(3);
});

it('gives a reader no link and no room, and asks /_teme/session nothing', function (): void {
    visit(dtUrl($this->site, 'longRead'))
        ->assertSee('A year on the harbour')
        ->assertDontSee('Edit post')
        ->assertScript("document.querySelectorAll('.teme-edit-link, .teme-edit-room').length === 0")
        ->assertScript("performance.getEntriesByType('resource').some((entry) => entry.name.includes('/_teme/session')) === false");
});
