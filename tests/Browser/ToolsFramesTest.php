<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
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

    // The page cache on, in a folder of this test's own; the site's address is left as it is, so the browser stays on its server.
    $this->folder = sys_get_temp_dir().'/teme-tools-frames-'.getmypid().'-'.uniqid();
    config(['page_cache.enabled' => true, 'page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->folder]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.index'))->assertOk();
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->folder));

dataset('tool frames', [
    'Import' => ['/admin/tools/import', 'Import'],
]);

it('draws :dataset on one page pattern, one heading, nothing sideways and no small target at 375 pixels', function (string $url, string $title): void {
    $page = cmSignIn($this->admin)->resize(375, 812);
    cmNavigate($page, $url);
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-slot="page"]\').length === 1');

    expect($page->script('() => [...document.querySelectorAll("main h1")].map((h) => h.textContent.trim())'))->toBe([$title])
        ->and($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue()
        ->and(cmSmallTargets($page)['small'])->toBe([]);
})->with('tool frames');
