<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->fake = importFake();
    importPeople();

    // The connect form as the screen sends it.
    $this->connect = fn (array $changes = []): array => array_replace(['address' => FakeCompanion::SITE, 'user_name' => FakeCompanion::USER, 'application_password' => FakeCompanion::PASSWORD], $changes);

    // The choices form as the screen sends it, every default kept.
    $this->choices = fn (array $changes = []): array => array_replace([
        'menu_locations' => ['primary' => 'primary', 'footer_navigation' => ''],
        'templates' => ['template-full-width.php' => '', 'template-landing.php' => ''],
        'roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'author'],
        'owner' => (string) $this->admin->id,
        'outside_images' => [],
        'pause' => '1000',
    ], $changes);

    // A connected site, its analysis read, and a run held on the queue.
    $this->site = function (): ImportSource {
        $source = importSource($this->admin);
        app(ImportAnalysis::class)->read($source->id);

        return $source;
    };
    $this->running = function (ImportSource $source): ImportRun {
        Queue::fake();
        $result = scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($source->id, ImportRunKind::Full, ['pause_ms' => 0], [], (string) $this->admin->id)));

        return ImportRun::query()->findOrFail($result['runId']);
    };
    $this->show = fn (ImportSource $source): string => "/admin/tools/import/{$source->id}";
});

it('opens Tools › Import for administrators alone (I-1707)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools/import')->assertStatus($role === 'administrator' ? 200 : 403);
})->with(['administrator', 'editor', 'author', 'contributor']);

it('shows the connect form, and the plugin Teme expects, while no site is connected (I-1711)', function (): void {
    $this->actingAs($this->admin)->get('/admin/tools/import')
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/import/index')
            ->where('sites', [])
            ->where('companion', ['version' => '0.2.0', 'releases' => null])
            ->has('siteTimezone'));
});

it('lists the sites Teme knows, with where each stands', function (): void {
    $source = ($this->site)();
    $run = importRun($source, $this->admin);
    app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($source->id, (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/tools/import')
        ->assertInertia(fn (Assert $page) => $page->where('sites', [[
            'id' => $source->id,
            'address' => 'https://old.example',
            'host' => 'old.example',
            'state' => 'finished',
            'stateLabel' => 'Imported',
            'pluginVersion' => $source->plugin_version,
            'forgotten' => true,
            'lastImportAt' => $run->started_at->utc()->toIso8601String(),
            'href' => "/admin/tools/import/{$source->id}",
        ]]));
});

it('connects a site and opens its page, saying so', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/tools/import', ($this->connect)());
    $source = ImportSource::query()->sole();

    $response->assertRedirect("/admin/tools/import/{$source->id}")
        ->assertInertiaFlash('toast.message', 'Connected to https://old.example.');

    expect($source->user_name)->toBe('ada');
});

it('never keeps the application password in the session when connecting is refused (I-1708)', function (): void {
    $this->fake->answer('settings', 401, ['code' => 'rest_not_logged_in', 'message' => 'You are not currently logged in.', 'data' => ['status' => 401]]);

    $this->actingAs($this->admin)->from('/admin/tools/import')->post('/admin/tools/import', ($this->connect)())
        ->assertRedirect('/admin/tools/import')
        ->assertSessionHasErrors(['application_password' => 'WordPress didn’t accept this user name and application password. If both are right, the site’s server may be removing the sign-in header before WordPress sees it.'])
        ->assertSessionMissing('_old_input');

    expect(json_encode(session()->all()))->not->toContain('EFGH')
        ->and(ImportSource::query()->count())->toBe(0);
});

it('opens a site at Analyze and Choose before its first import, its plan deferred, with the custom types’ warning', function (): void {
    $this->fake->items('analyze', tiReport(['content_types' => ['post_types' => ['idb_movie' => ['counts' => ['publish' => '500']]]]]));
    $source = ($this->site)();

    $this->actingAs($this->admin)->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/import/show')
            ->where('site.host', 'old.example')
            ->where('site.forgotten', false)
            ->where('step', 'plan')
            ->where('run', null)
            ->where('job', null)
            ->missing('plan')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('plan.problem', null)
                ->where('plan.analysis.comes.0.count', 126)
                ->where('plan.analysis.warning', ['custom' => 500, 'total' => 628])
                ->where('plan.form.owner', (string) $this->admin->id)
                ->where('plan.form.menuLocations.0', ['key' => 'primary', 'value' => 'primary'])
                ->where('plan.remembered', null)));
});

it('says why when the site can’t be read for its plan', function (): void {
    $source = importSource($this->admin);
    $this->fake->answer('analyze', 503);

    $this->actingAs($this->admin)->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('plan.problem', 'The site is busy or had an error (503). Try again in a minute.')
            ->where('plan.analysis', null)
            ->where('plan.form', null)));
});

it('starts an import with the choices made once the password is confirmed, saying so (I-1707)', function (): void {
    $source = ($this->site)();
    Queue::fake();

    $this->actingAs($this->admin)->withSession([...scConfirmed(), "import.choices.{$source->id}" => ['owner' => '1']])->from(($this->show)($source))
        ->post(($this->show)($source).'/start', ($this->choices)(['outside_images' => ['cdn.partner.example'], 'pause' => '2000']))
        ->assertRedirect(($this->show)($source))
        ->assertInertiaFlash('toast.message', 'Started importing old.example.')
        ->assertSessionMissing("import.choices.{$source->id}");

    $run = ImportRun::query()->sole();

    expect($run->kind)->toBe(ImportRunKind::Full)
        ->and($run->options)->toBe(['pause_ms' => 2000])
        ->and($run->choices['outside_images'])->toBe(['cdn.partner.example'])
        ->and($run->choices['roles'])->toEqualCanonicalizing(['wpseo_manager' => 'editor', 'wpseo_editor' => 'author'])
        ->and($run->choices['owner'])->toBe((string) $this->admin->id)
        ->and($run->started_by)->toBe($this->admin->id)
        // The application password is in neither the queue's payloads nor the kept analysis (I-1708).
        ->and(serialize(Queue::pushedJobs()))->not->toContain('EFGH')
        ->and(json_encode(Cache::get(ImportAnalysis::key($source->id))))->not->toContain('EFGH');
});

it('keeps the choices across the password’s confirmation, so nothing is chosen twice', function (): void {
    $source = ($this->site)();
    $kept = [
        'menu_locations' => ['primary' => 'primary', 'footer_navigation' => null],
        'templates' => ['template-full-width.php' => 'full-width', 'template-landing.php' => null],
        'roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'author'],
        'owner' => (string) $this->admin->id,
        'outside_images' => [],
        'pause' => 1000,
    ];

    $this->actingAs($this->admin)->from(($this->show)($source))
        ->post(($this->show)($source).'/start', ($this->choices)(['templates' => ['template-full-width.php' => 'full-width', 'template-landing.php' => '']]))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas("import.choices.{$source->id}", $kept);

    expect(ImportRun::query()->count())->toBe(0);

    $this->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('plan.remembered', $kept)));
});

it('refuses choices that don’t fit, starting nothing', function (): void {
    $source = ($this->site)();

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(($this->show)($source))
        ->post(($this->show)($source).'/start', ($this->choices)(['owner' => '999999']))
        ->assertRedirect(($this->show)($source))
        ->assertSessionHasErrors(['owner' => 'Choose someone who can write on Teme.']);

    expect(ImportRun::query()->count())->toBe(0);
});

it('asks for the analysis before an import starts', function (): void {
    $source = importSource($this->admin);

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(($this->show)($source))
        ->post(($this->show)($source).'/start', ($this->choices)())
        ->assertSessionHasErrors(['choices' => 'Analyze the site before you start: Teme needs its report.']);

    expect(ImportRun::query()->count())->toBe(0);
});

it('shows a running import’s steps, its job and nothing to plan', function (): void {
    $source = ($this->site)();
    $run = ($this->running)($source);

    $this->actingAs($this->admin)->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'import')
            ->where('plan', null)
            ->where('run.id', $run->id)
            ->where('run.state', 'running')
            ->where('run.stateLabel', 'Running')
            ->where('run.kindLabel', 'Full import')
            ->where('run.counts', ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => 0])
            ->where('run.steps', [
                ['key' => 'fixture-people', 'label' => 'People', 'state' => 'now'],
                ['key' => 'fixture-notes', 'label' => 'Notes', 'state' => 'next'],
            ])
            ->where('job.label', 'Import from old.example')
            ->where('job.message', 'Now: People'));
});

it('stops an import by cancelling its job, as whoever stopped it, and tells its starter', function (): void {
    $source = ($this->site)();
    $run = ($this->running)($source);

    $this->actingAs($this->grace)->from(($this->show)($source))->post("/admin/tools/import/runs/{$run->id}/stop")
        ->assertRedirect(($this->show)($source))
        ->assertInertiaFlash('toast.message', 'Stopped the import of old.example. Carry it on whenever you like: it picks up where it stopped.');

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->fresh()->stop_reason)->toBe('by-hand')
        ->and(app(TrackedJobList::class)->ofRun('import', $run->id)[0])->toMatchArray(['state' => 'cancelled', 'message' => 'Cancelled by Grace Admin.'])
        ->and(DB::table('inbox_items')->where('user_id', $this->admin->id)->where('kind', 'job.cancelled')->exists())->toBeTrue();
});

it('stops an import that has no job of its own directly', function (): void {
    $source = ($this->site)();
    $run = ($this->running)($source);
    DB::table('tracked_jobs')->delete();

    $this->actingAs($this->admin)->from(($this->show)($source))->post("/admin/tools/import/runs/{$run->id}/stop")
        ->assertInertiaFlash('toast.type', 'success');

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped);
});

it('carries a stopped import on once the password is confirmed, saying so', function (): void {
    $source = ($this->site)();
    $run = ($this->running)($source);
    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(($this->show)($source))->post("/admin/tools/import/runs/{$run->id}/carry-on")
        ->assertInertiaFlash('toast.message', 'Carrying on the import of old.example.');

    expect($run->fresh()->state)->toBe(ImportRunState::Running);
});

it('starts a catch-up with the last finished import’s choices and options', function (): void {
    $source = ($this->site)();
    importRun($source, $this->admin, choices: ['owner' => (string) $this->grace->id], options: ['pause_ms' => 500]);
    Queue::fake();

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(($this->show)($source))->post(($this->show)($source).'/catch-up')
        ->assertInertiaFlash('toast.message', 'Started a catch-up of old.example.');

    $catchUp = ImportRun::query()->where('kind', ImportRunKind::CatchUp->value)->sole();

    expect($catchUp->choices)->toBe(['owner' => (string) $this->grace->id])
        ->and($catchUp->options)->toBe(['pause_ms' => 500]);
});

it('reads the site again when asked', function (): void {
    $source = ($this->site)();

    $this->actingAs($this->admin)->from(($this->show)($source))->post(($this->show)($source).'/analyze')
        ->assertInertiaFlash('toast.message', 'Teme will read the site report again.');

    expect(app(ImportAnalysis::class)->kept($source->id))->toBeNull();
});

it('forgets a site, says to revoke its password in WordPress, and then opens it at Connect again (I-1708)', function (): void {
    $source = ($this->site)();

    $this->actingAs($this->admin)->from(($this->show)($source))->post(($this->show)($source).'/forget')
        ->assertInertiaFlash('toast.message', 'Teme has forgotten the application password for https://old.example. Revoke it in WordPress too: Users › Profile › Application Passwords.');

    expect($source->fresh()->credential)->toBeNull();

    $this->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page->where('step', 'connect')->where('site.forgotten', true)->where('plan', null));
});

it('refuses to forget a site while it is being imported, saying why', function (): void {
    $source = ($this->site)();
    ($this->running)($source);

    $this->actingAs($this->admin)->from(($this->show)($source))->post(($this->show)($source).'/forget')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Stop the import that’s running first: it needs the application password.');

    expect($source->fresh()->credential)->not->toBeNull();
});

it('keeps other hosts across the password confirmation on the report step', function (): void {
    ['source' => $sourceId] = ImportRecords::finishedImport($this->admin->id);
    $source = ImportSource::query()->findOrFail($sourceId);

    $this->actingAs($this->admin)->from(($this->show)($source))
        ->post(($this->show)($source).'/hosts', ['hosts' => ['stage.example.com']])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas('import.other-hosts.'.$source->id, ['stage.example.com']);

    $this->get(($this->show)($source))
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'report')
            ->where('rememberedHosts', ['stage.example.com']));
});

it('refuses every action to someone who may not import, changing nothing (I-1707)', function (): void {
    $source = ($this->site)();
    $run = ($this->running)($source);
    $tables = fn (): string => collect(['import_sources', 'import_runs', 'import_map', 'tracked_jobs', 'security_events'])
        ->map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson())->implode('|');
    $before = $tables();

    $this->actingAs($this->editor)->withSession(scConfirmed());

    foreach ([
        ['get', '/admin/tools/import'], ['post', '/admin/tools/import'], ['get', ($this->show)($source)],
        ['post', ($this->show)($source).'/analyze'], ['post', ($this->show)($source).'/forget'], ['post', ($this->show)($source).'/start'],
        ['post', ($this->show)($source).'/catch-up'], ['post', "/admin/tools/import/runs/{$run->id}/stop"], ['post', "/admin/tools/import/runs/{$run->id}/carry-on"],
    ] as [$method, $uri]) {
        $this->{$method}($uri, $method === 'post' ? ($this->connect)() : [])->assertForbidden();
    }

    expect($tables())->toBe($before);
});

it('answers not found for a site or a run Teme doesn’t have', function (): void {
    $missing = strtolower((string) Str::ulid());

    $this->actingAs($this->admin)->get("/admin/tools/import/{$missing}")->assertNotFound();
    $this->actingAs($this->admin)->post("/admin/tools/import/runs/{$missing}/stop")->assertNotFound();
});
