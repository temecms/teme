<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Playwright;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

/**
 * Waits until the JavaScript expression $condition is true on the page, for
 * as long as the plugin's own timeout: its assertions look once and answer
 * at once. Tools › Import's own copy of the shared wait, under its own name.
 */
function tiWaitUntil(mixed $page, string $condition): mixed
{
    $timeout = Playwright::timeout();
    $met = $page->script("async () => { const until = Date.now() + {$timeout}; while (Date.now() < until) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 50)); } return false; }");

    expect($met)->toBeTrue("The page did not satisfy [{$condition}] within {$timeout} ms.");

    return $page;
}

/**
 * The condition that an element matching $selector is on the page.
 */
function tiPresent(string $selector): string
{
    return 'document.querySelector('.json_encode($selector).') !== null';
}

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
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->fake = importFake();
    importPeople();

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get('/admin/tools/import')->assertOk();
});

it('passes axe on Tools › Import and a site’s first steps, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-connect"]'));
    $page->assertSee('Install and activate the Teme Companion plugin, version 0.2.0 or later.')
        ->assertNoAccessibilityIssues(3);
    $connect = cmSmallTargets($page);

    // The warning drawn too, so axe reads it in both schemes.
    $this->fake->items('analyze', tiReport(['content_types' => ['post_types' => ['idb_movie' => ['counts' => ['publish' => '500']]]]]));
    $source = importSource($this->admin);
    $page->navigate("/admin/tools/import/{$source->id}");
    tiWaitUntil($page, tiPresent('[data-test="import-start"]'));
    $page->assertSeeIn('[data-test="import-analysis"]', 'What comes across')
        ->assertSeeIn('[data-test="import-warning"]', 'Most of this site’s addresses belong to custom types: 500 of 628 published items.')
        ->assertNoAccessibilityIssues(3);
    $site = cmSmallTargets($page);

    expect($connect['small'])->toBe([])
        ->and($connect['checked'])->toBeGreaterThan(0)
        ->and($site['small'])->toBe([])
        ->and($site['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('connects a site, reads what comes across, confirms the password and starts the import, its choices kept (I-227)', function (): void {
    Queue::fake();

    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-connect"]'));
    $page->type('#import-address', FakeCompanion::SITE)
        ->type('#import-user-name', FakeCompanion::USER)
        ->type('#import-application-password', FakeCompanion::PASSWORD)
        ->click('[data-test="import-connect"]');

    tiWaitUntil($page, tiPresent('[data-test="import-start"]'));
    $page->assertSeeIn('[data-test="import-analysis"]', 'Movies')
        ->assertSeeIn('[data-test="import-analysis"]', 'A custom taxonomy. Teme can’t import these yet.')
        ->click('#import-host-0')
        ->click('[data-test="import-start"]');

    tiWaitUntil($page, tiPresent('#password'));
    $page->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');

    tiWaitUntil($page, tiPresent('[data-test="import-start"]'));
    tiWaitUntil($page, 'document.querySelector('.json_encode('#import-host-0').')?.getAttribute("data-state") === "checked"');
    $page->click('[data-test="import-start"]');

    tiWaitUntil($page, tiPresent('[data-test="import-stop"]'));
    $page->assertSee('Started importing old.example.')
        ->assertSeeIn('[data-test="import-step"]', 'Import');

    $run = ImportRun::query()->sole();

    expect($run->state)->toBe(ImportRunState::Running)
        ->and($run->choices['outside_images'])->toBe(['cdn.partner.example']);
});
it('marks one current step on a site’s page, Choose once the plan has arrived', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-connect"]'));
    $page->type('#import-address', FakeCompanion::SITE)
        ->type('#import-user-name', FakeCompanion::USER)
        ->type('#import-application-password', FakeCompanion::PASSWORD)
        ->click('[data-test="import-connect"]');

    tiWaitUntil($page, tiPresent('[data-test="import-start"]'));

    expect($page->script('() => document.querySelector(\'[data-test="import-step"][data-slot="step-bar"]\') !== null'))->toBeTrue()
        ->and($page->script('() => [...document.querySelectorAll(\'[data-test="import-step"] [aria-current="step"]\')].map((step) => step.textContent.trim())'))->toHaveCount(1)
        ->and($page->script('() => document.querySelector(\'[data-test="import-step"] [aria-current="step"]\').textContent'))->toContain('Choose')
        ->and($page->script('() => [...document.querySelectorAll(\'[data-test="import-step"] > li\')].slice(0, 2).map((step) => step.dataset.state)'))->toBe(['done', 'done']);
});

it('marks no step current on a stopped import, the steps it finished still done', function (): void {
    $source = ImportRecords::source(['created_by' => $this->admin->id]);
    ImportRecords::run($source, ['state' => 'stopped', 'phase' => 'fixture-notes', 'stop_reason' => 'by-hand', 'stop_message' => 'Stopped on request. Run it again to carry on from where it stopped.', 'started_by' => $this->admin->id, 'ended_at' => now()]);

    $page = cmSignIn($this->admin)->navigate("/admin/tools/import/{$source}");
    tiWaitUntil($page, tiPresent('[aria-label="Steps of this import"]'));

    expect($page->script('() => document.querySelectorAll(\'[aria-label="Steps of this import"] [aria-current]\').length'))->toBe(0)
        ->and($page->script('() => document.querySelectorAll(\'[aria-label="Steps of this import"] [data-state="done"]\').length'))->toBe(1);
});

it('passes axe on a finished import’s report, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    ['source' => $source] = ImportRecords::finishedImport($this->admin->id);

    $page = cmSignIn($this->admin)->navigate("/admin/tools/import/{$source}");
    tiWaitUntil($page, tiPresent('[data-test="import-report"]'));
    $page->assertSeeIn('[data-test="import-report"]', 'How many')
        ->assertSeeIn('[data-test="import-report"]', 'Teme refused it: The title may not be longer than 300 characters.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('forgets a site after asking, and says to revoke its password in WordPress (I-1708)', function (): void {
    ['source' => $source] = ImportRecords::finishedImport($this->admin->id);

    $page = cmSignIn($this->admin)->navigate("/admin/tools/import/{$source}");
    tiWaitUntil($page, tiPresent('[data-test="import-forget"]'));
    $page->click('[data-test="import-forget"]');

    tiWaitUntil($page, tiPresent('[role="dialog"]'));
    $page->assertScript('document.querySelector(\'[role="dialog"]\').textContent.includes("What was imported stays")', true)
        ->click('[role="dialog"] button:text-is("Forget this site")');

    tiWaitUntil($page, tiPresent('#import-application-password'));
    $page->assertSee('Teme has forgotten the application password for https://old.example. Revoke it in WordPress too: Users › Profile › Application Passwords.');

    expect(ImportSource::query()->findOrFail($source)->credential)->toBeNull();
});
