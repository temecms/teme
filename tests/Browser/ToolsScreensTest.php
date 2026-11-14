<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
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

    // The page cache on, in a folder of this test's own; the site's address is left as it is, so the browser stays on its server.
    $this->folder = sys_get_temp_dir().'/teme-tools-browser-'.getmypid().'-'.uniqid();
    config(['page_cache.enabled' => true, 'page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->folder]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('tools.page-cache.show'))->assertOk();
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->folder));

it('passes axe on Tools and Tools › Page cache, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/tools')
        ->assertSee('Utilities for looking after the site.')
        ->assertNoAccessibilityIssues(3);

    $hub = cmSmallTargets($page);

    $page->click('[data-test="tool-page-cache"]')
        ->assertSee('You rarely need to clear it.')
        ->assertSee('Files on this server')
        ->assertNoAccessibilityIssues(3);

    $screen = cmSmallTargets($page);

    expect($hub['small'])->toBe([])
        ->and($hub['checked'])->toBeGreaterThan(0)
        ->and($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('clears the page cache from its screen, and then says who cleared it', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/tools/page-cache')
        ->assertSee('Never')
        ->click('[data-test="page-cache-clear"]')
        ->assertSee('Page cache cleared. Each page is drawn afresh on its next visit.')
        ->assertSee('by Ada Admin');

    expect(DB::table('security_events')->where('type', 'page_cache.cleared')->count())->toBe(1);
});
