<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // Two themes: the one in use declares a region; the other declares none.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/plain')]]);
    themeActivate('good');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->region = regionCreate($this->editor);

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->editor)->get('/admin/regions')->assertOk();
    $this->actingAs($this->editor)->get("/admin/content/{$this->region->id}/edit")->assertOk();
});

it('passes axe on Regions in both schemes, each region showing its cards by their labels and the most blocks it holds', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/regions')
        ->assertSee('At most 5 blocks')
        // Card labels, not the manifest's names: assertSee would match either case.
        ->assertScript("[...document.querySelectorAll('[data-test=\"region-card\"]')].map((chip) => chip.textContent).join(', ')", 'Callout, Button')
        ->assertNotPresent('[data-test="unplaced"]')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('lists a region kept from a theme not in use under Unplaced, passing axe in both schemes', function (string $scheme): void {
    themeActivate('plain');
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $this->actingAs($this->editor)->get('/admin/regions')->assertOk();

    $page = cmSignIn($this->editor)
        ->navigate('/admin/regions')
        ->assertSee('Fixture Plain has no regions')
        ->assertSeeIn('[data-test="unplaced"]', 'From other themes')
        ->assertSeeIn('[data-test="unplaced"]', 'After the article')
        ->assertSeeIn('[data-test="unplaced"]', 'Fixture Good')
        ->assertSeeIn('[data-test="unplaced"]', 'Kept as they were from themes you aren’t using. Each shows again if you switch back to its theme.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('heads a region\'s editor with its name, its theme and what it takes, passing axe in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate("/admin/content/{$this->region->id}/edit")
        ->assertSee('Takes text and these cards: Callout and Button. At most 5 blocks.')
        ->assertScript("document.querySelectorAll('h1').length", 1)
        ->assertScript("document.querySelector('h1')?.textContent", 'Region: After the article, used by Fixture Good')
        // The editor folds the admin sidebar as it opens; axe reads its labels once they have faded.
        ->assertScript("[...document.querySelectorAll('[data-sidebar=\"group-label\"]')].flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length", 0)
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
