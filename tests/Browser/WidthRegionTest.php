<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * A region declared with minWidth, in a real browser: below the width nothing of
 * it is asked for or drawn; from it, core's script fills the placeholder and the
 * page's content security policy lets the region's own script run.
 */
beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/wide')]]);
    themeActivate('wide');

    // One application serves every request in the test's process: each one starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $region = regionCreate($this->admin, 'wide', 'half-page');
    scElevated(function () use ($region): void {
        regionSave($region, $this->admin, [['type' => 'site-code', 'attrs' => [
            'id' => (string) Str::ulid(),
            'code' => '<p>Half page ad</p><script>window.halfPage = 1</script>',
            'origins' => [],
        ]]]);
        listsPublish($region, $this->admin);
    });
    pageArticle($editor, 'A story');

    // The suite's first visit is its cold one: draw the page once here, so the browser's isn't.
    addrGet('/a-story/')->assertOk();
});

it('asks for nothing and draws nothing of the region below its width', function (): void {
    $page = cmNavigate(visit('/a-story/')->resize(375, 812), '/a-story/');
    cmWaitUntil($page, 'document.querySelector("[data-teme-region-min]") !== null');

    $page->assertScript('document.body.innerText.includes("Half page ad")', false)
        ->assertScript('typeof window.halfPage', 'undefined')
        ->assertScript('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/_teme/region")).length', 0)
        ->assertNoConsoleLogs();
});

it('fills the region from its width, runs its script, and the console shows no policy error', function (): void {
    $page = cmNavigate(visit('/a-story/')->resize(1440, 900), '/a-story/');
    cmWaitUntil($page, 'document.body.innerText.includes("Half page ad")');

    $page->assertScript('window.halfPage', 1)
        ->assertScript('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/_teme/region")).length', 1)
        ->assertNoConsoleLogs();
});
