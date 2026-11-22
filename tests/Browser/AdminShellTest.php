<?php

declare(strict_types=1);

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

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('passes axe at a phone\'s width, and its sidebar\'s drawer holds the title that names it', function (): void {
    $page = cmSignIn($this->admin)->resize(375, 812);

    $page->assertNoAccessibilityIssues(3)
        ->assertScript('document.querySelectorAll(\'[data-slot="sheet-header"]\').length', 0);

    // Read with a script: the plugin's assertVisible() never answered on this drawer.
    $page->click('[data-sidebar="trigger"]')
        ->assertScript("(() => { const drawer = document.querySelector('[data-mobile=\"true\"][role=\"dialog\"]'); const title = drawer && document.getElementById(drawer.getAttribute('aria-labelledby')); return title !== null && drawer.contains(title) && title.textContent === 'Main menu'; })()");
});

it('closes the phone menu when a page is chosen', function (): void {
    $posts = route('content.index', ['type' => 'posts'], false);
    $page = cmSignIn($this->admin)->resize(375, 812);

    $page->click('[data-sidebar="trigger"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-mobile="true"][role="dialog"]\') !== null');

    $page->click("a[data-slot=\"sidebar-menu-button\"][href=\"{$posts}\"]");
    cmWaitUntil($page, "location.pathname === '{$posts}'");
    cmWaitUntil($page, <<<'JS'
        (() => {
            const drawer = document.querySelector('[data-mobile="true"][role="dialog"]');
            return drawer === null || drawer.getAttribute('data-state') === 'closed';
        })()
        JS);

    expect($page->script(<<<'JS'
        () => {
            const drawer = document.querySelector('[data-mobile="true"][role="dialog"]');
            return drawer === null || drawer.getAttribute('data-state') === 'closed';
        }
        JS))->toBeTrue();
});
