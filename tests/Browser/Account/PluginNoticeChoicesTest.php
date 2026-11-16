<?php

declare(strict_types=1);

use App\Domain\Notices\NoticePreferences;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Preferences\PreferenceRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    PluginFixtures::use('relay');
    $manifest = PluginFixtures::manifest('relay');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/relay',
        'code' => 'relay',
        'version' => $manifest['version'],
        'consent' => ['capabilities' => $manifest['capabilities'], 'hosts' => [], 'hooks' => []],
    ]);
    app()->forgetScopedInstances();
    app()->forgetInstance(PreferenceRegistry::class);

    // Each request starts afresh, as on a real server (JobsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Grace Admin'])->assignRole('administrator');
    $this->failed = NoticePreferences::PREFIX.'plugin_relay.job_failed';

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('notifications.edit'))->assertOk();
});

afterEach(fn () => PluginFixtures::forget());

it('turns one channel of a plugin’s kind off and keeps it off after a reload', function (int $width): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/account/notifications');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-notices\"]') !== null");

    $page->assertScript("document.querySelectorAll('[data-test=\"plugin-notices\"]').length", 1)
        ->assertScript("document.querySelector('[data-test=\"plugin-notices\"]').textContent.trim()", 'Relay')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');

    $page->click('[id="pref-'.$this->failed.'-inbox"]');
    $page->click('button[type=submit]');
    cmWaitUntil($page, "document.body.textContent.includes('Notifications saved.')");

    $page->navigate('/admin/account/notifications');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-notices\"]') !== null");

    $page->assertScript("document.getElementById('pref-{$this->failed}-inbox').getAttribute('data-state')", 'unchecked');
})->with([1280, 375]);

it('passes axe with a plugin’s kinds, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/notifications');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-notices\"]') !== null");
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
