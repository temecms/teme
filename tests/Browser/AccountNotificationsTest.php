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

    // Each request starts afresh, as on a real server (JobsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->author)->get(route('notifications.edit'))->assertOk();
});

it('passes axe on Account › Notifications, every target 24 pixels or more', function (): void {
    $page = cmSignIn($this->author)->navigate('/admin/account/notifications');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('fieldset') !== null && document.body.textContent.includes('Open inbox')
        JS);
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
});

it('saves an unticked email and keeps it unticked after a reload', function (): void {
    $page = cmSignIn($this->author)->navigate('/admin/account/notifications');
    cmWaitUntil($page, "document.querySelector('fieldset button[role=checkbox]') !== null");

    $page->click('[id="pref-notifications.comments.waiting"]');
    $page->click('button[type=submit]');
    cmWaitUntil($page, "document.body.textContent.includes('Notifications saved.')");

    $page->navigate('/admin/account/notifications');
    cmWaitUntil($page, "document.querySelector('fieldset button[role=checkbox]') !== null");

    $page->assertScript("document.getElementById('pref-notifications.comments.waiting').getAttribute('data-state')", 'unchecked');
});

it('says there are no emails to choose while comments are off', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $page = cmSignIn($this->author)->navigate('/admin/account/notifications');
    cmWaitUntil($page, "document.body.textContent.includes('There are no emails to choose here.')");

    $page->assertSee('There are no emails to choose here.');
});
