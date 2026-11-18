<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\PlatformCheck;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Settings › Updates: the Dokploy API key field, and the sentence the
 * updater's last try left, at a desk's width and a phone's.
 */

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    Cache::put(PlatformCheck::KEY, ['refusal' => 'Teme has no Dokploy API key. Paste one in Settings › Updates.', 'checkedAt' => now()->toIso8601String()], now()->addDay());

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get('/admin/settings/updates')->assertOk();
});

it('passes axe on Settings › Updates with every target 24 pixels or more, at 1280 and 375, in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/settings/updates');
    $page->assertSee('Dokploy API key')
        ->assertSeeIn('[data-test="settings-notice"]', 'Updating from Tools › Updates needs a Dokploy API key. Paste it below.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark'])->with([1280, 375]);
