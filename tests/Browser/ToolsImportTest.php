<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportFile;
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
    tiWaitUntil($page, tiPresent('[data-test="import-way-connect"]'));
    $page->click('[data-test="import-way-connect-button"]');
    tiWaitUntil($page, tiPresent('[data-test="import-connect"]'));
    $page->assertSee('Install and activate the Teme Companion plugin')
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
    tiWaitUntil($page, tiPresent('[data-test="import-way-connect"]'));
    $page->click('[data-test="import-way-connect-button"]');
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

    // Back from the password, the start carries on by itself, with the choices kept: no second Start.
    tiWaitUntil($page, tiPresent('[data-test="import-stop"]'));
    $page->assertSee('Started importing old.example.')
        ->assertSeeIn('[data-test="import-step"]', 'Import');

    $run = ImportRun::query()->sole();

    expect($run->state)->toBe(ImportRunState::Running)
        ->and($run->choices['outside_images'])->toBe(['cdn.partner.example']);
});
it('marks one current step on a site’s page, Choose once the plan has arrived', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-way-connect"]'));
    $page->click('[data-test="import-way-connect-button"]');
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

it('saves a suggested other address of this site, and passes axe at 1280 and 375 in light and dark', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $this->fake->items('analyze', tiReport([
        'content' => [
            'html' => [
                'links' => [
                    'external_hosts' => [
                        'stage.old.example' => 143,
                        'other.org' => 50,
                    ],
                ],
            ],
        ],
    ]));
    $source = importSource($this->admin);

    $page = cmSignIn($this->admin)->navigate("/admin/tools/import/{$source->id}");
    tiWaitUntil($page, tiPresent('[data-test="import-other-hosts"]'));
    $page->resize(1280, 800)
        ->assertSee('Other addresses of this site')
        ->assertSee('stage.old.example')
        ->assertNoAccessibilityIssues(3);
    $wide = cmSmallTargets($page);

    $page->resize(375, 800)->assertNoAccessibilityIssues(3);
    $narrow = cmSmallTargets($page);

    $page->resize(1280, 800)
        ->click('[data-test="import-other-host-stage.old.example"]')
        ->click('[data-test="import-other-hosts-save"]');

    tiWaitUntil($page, tiPresent('#password'));
    $page->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');

    // After the password, back on the site page with the tick kept; save again.
    tiWaitUntil($page, tiPresent('[data-test="import-other-hosts-save"]'));
    $page->click('[data-test="import-other-hosts-save"]');

    tiWaitUntil($page, tiPresent('[data-test="import-other-hosts-listed"]'));
    $page->assertSeeIn('[data-test="import-other-hosts-listed"]', 'stage.old.example');

    expect(ImportSource::query()->findOrFail($source->id)->other_hosts)->toBe(['stage.old.example'])
        ->and($wide['small'])->toBe([])
        ->and($narrow['small'])->toBe([]);
})->with(['light', 'dark']);

it('unticks a saved other address and keeps the rest', function (): void {
    $source = importSource($this->admin);
    $source->forceFill(['other_hosts' => ['stage.old.example', 'cdn.old.example']])->save();
    $this->fake->items('analyze', tiReport());

    $page = cmSignIn($this->admin)->navigate("/admin/tools/import/{$source->id}");
    tiWaitUntil($page, tiPresent('[data-test="import-other-host-listed-stage.old.example"]'));
    $page->click('[data-test="import-other-host-listed-stage.old.example"]')
        ->click('[data-test="import-other-hosts-save"]');

    tiWaitUntil($page, tiPresent('#password'));
    $page->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');

    tiWaitUntil($page, tiPresent('[data-test="import-other-hosts-save"]'));
    $page->click('[data-test="import-other-hosts-save"]');

    tiWaitUntil($page, 'document.querySelector('.json_encode('[data-test="import-other-hosts-listed"]').') !== null && !document.querySelector('.json_encode('[data-test="import-other-host-listed-stage.old.example"]').')');
    $page->assertSeeIn('[data-test="import-other-hosts-listed"]', 'cdn.old.example');

    expect(ImportSource::query()->findOrFail($source->id)->other_hosts)->toBe(['cdn.old.example']);
});

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

it('uploads an export file through the page and shows it being read', function (): void {
    Queue::fake();

    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-way-upload"]'));
    $page->click('[data-test="import-way-upload-button"]');
    tiWaitUntil($page, tiPresent('[data-test="import-upload"]'));
    $page->assertSee('Upload an export file')
        ->attach('[data-test="import-upload-input"]', base_path('tests/Fixtures/Import/wxr/small.xml'));

    tiWaitUntil($page, tiPresent('[data-test="import-upload-reading"]'));
    $page->assertSee('Reading the file: counting its posts, pages, media and authors…')
        ->assertNoJavaScriptErrors();

    expect(ImportFile::query()->sole()->state)->toBe('reading');
});

it('imports a site from its export file, from the upload to the report', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-way-upload"]'));
    $page->click('[data-test="import-way-upload-button"]');
    tiWaitUntil($page, tiPresent('[data-test="import-upload"]'));
    $page->attach('[data-test="import-upload-input"]', base_path('tests/Fixtures/Import/wxr/small.xml'));

    // Read, the site's plan opens.
    tiWaitUntil($page, tiPresent('[data-test="import-file-plan"]'));
    $page->assertSeeIn('[data-test="import-step"]', 'Upload')
        ->assertSee('Export file small.xml')
        ->assertSee('What comes across')
        ->assertSee('Not in an export file')
        ->assertSee('To bring these too, connect the live site with Teme Companion instead.')
        ->assertNoAccessibilityIssues(3)
        ->click('[data-test="import-start"]');

    tiWaitUntil($page, tiPresent('#password'));
    $page->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');

    // Back from the password, the start carries on by itself.
    tiWaitUntil($page, tiPresent('[data-test="import-upload-newer"]'));
    $page->assertSeeIn('[data-test="import-step"]', 'Report')
        ->assertSee('Bring what changed')
        ->assertSee('Delete the uploaded file')
        ->assertSee('Teme deletes the uploaded file on')
        ->assertNoJavaScriptErrors();

    $run = ImportRun::query()->sole();

    // Once more without the start's toast, which fades in over the page.
    $page->navigate("/admin/tools/import/{$run->source_id}");
    tiWaitUntil($page, tiPresent('[data-test="import-delete-file"]'));
    $page->assertNoAccessibilityIssues(3);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->file_id)->toBe(ImportFile::query()->sole()->id);
});

it('carries an export file’s start on after the password, and shows the Import step at once', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/import');
    tiWaitUntil($page, tiPresent('[data-test="import-way-upload"]'));
    $page->click('[data-test="import-way-upload-button"]');
    tiWaitUntil($page, tiPresent('[data-test="import-upload"]'));
    $page->attach('[data-test="import-upload-input"]', base_path('tests/Fixtures/Import/wxr/small.xml'));
    tiWaitUntil($page, tiPresent('[data-test="import-file-plan"]'));

    // The run waits on the queue, as it does on a site with a worker.
    Queue::fake();
    $page->click('[data-test="import-start"]');
    tiWaitUntil($page, tiPresent('#password'));
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');

    tiWaitUntil($page, tiPresent('[data-test="import-progress"]'));
    $page->assertSeeIn('[data-test="import-step"]', 'Import')
        ->assertNoJavaScriptErrors();

    expect(ImportRun::query()->count())->toBe(1);
});
