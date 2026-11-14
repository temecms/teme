<?php

declare(strict_types=1);

use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // No phase registered, the address check included, whichever lanes have merged: the switch line stays unknown.
    importPhaseAlone();
    $this->admin = User::factory()->create()->assignRole('administrator');
    ['source' => $this->source, 'run' => $this->run] = ImportRecords::finishedImport($this->admin->id);
    $this->older = ImportRecords::run($this->source, ['started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)]);
    $this->show = "/admin/tools/import/{$this->source}";
});

it('opens a finished import at its report, with the site’s imports and the two files', function (): void {
    $this->actingAs($this->admin)->get($this->show)
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'report')
            ->where('run.id', $this->run)
            ->where('run.counts', ['created' => 11, 'updated' => 1, 'skipped' => 1, 'failed' => 2, 'warnings' => 3])
            ->where('report.problems', 3)
            ->where('report.ready', null)
            ->where('report.types.0.label', 'People')
            ->where('runs.0.id', $this->run)
            ->where('runs.1.id', $this->older)
            ->where('downloads', [
                'everything' => "/admin/tools/import/runs/{$this->run}/everything",
                'attention' => "/admin/tools/import/runs/{$this->run}/attention",
            ])
            ->where('latest', true));
});

it('shows an earlier import’s report when asked, at Report whatever the latest is doing, and never another site’s', function (): void {
    $other = ImportRecords::run(ImportRecords::source(['address' => 'https://other.example']));
    ImportRecords::run($this->source, ['state' => 'stopped', 'stop_reason' => 'by-hand', 'stop_message' => 'Stopped on request. Run it again to carry on from where it stopped.', 'started_at' => now(), 'ended_at' => now()]);

    $this->actingAs($this->admin)->get("{$this->show}?run={$this->older}")
        ->assertInertia(fn (Assert $page) => $page->where('step', 'report')->where('run.id', $this->older)->where('latest', false));

    $this->actingAs($this->admin)->get("{$this->show}?run={$other}")
        ->assertInertia(fn (Assert $page) => $page->where('step', 'import')->where('latest', true));
});

it('keeps a forgotten site’s report and imports on its page, under Connect again (I-1708)', function (): void {
    app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($this->source, (string) $this->admin->id));

    $this->actingAs($this->admin)->get($this->show)
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'connect')
            ->where('site.forgotten', true)
            ->where('run.id', $this->run)
            ->where('report.problems', 3)
            ->where('runs.1.id', $this->older)
            ->where('latest', true));
});

it('shows a stopped import’s report so far under its progress', function (): void {
    $stopped = ImportRecords::run($this->source, ['state' => 'stopped', 'stop_reason' => 'by-hand', 'stop_message' => 'Stopped on request. Run it again to carry on from where it stopped.', 'started_at' => now(), 'ended_at' => now()]);
    ImportRecords::entry($this->source, $stopped, 'post', '40', 'created');

    $this->actingAs($this->admin)->get($this->show)
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'import')
            ->where('run.id', $stopped)
            ->where('run.stopMessage', 'Stopped on request. Run it again to carry on from where it stopped.')
            ->where('report.types', [['type' => 'post', 'label' => 'Posts and pages', 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0]]));
});

it('downloads the full report and what needs attention as CSV files named for the site', function (): void {
    $date = now()->subHours(2)->utc()->format('Y-m-d');

    $full = $this->actingAs($this->admin)->get("/admin/tools/import/runs/{$this->run}/everything");
    $attention = $this->actingAs($this->admin)->get("/admin/tools/import/runs/{$this->run}/attention");

    $full->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload("import-old.example-{$date}.csv");
    $attention->assertOk()->assertDownload("import-old.example-{$date}-attention.csv");

    expect($full->streamedContent())->toStartWith("\xEF\xBB\xBFtype,wordpress_id,outcome,teme_type,teme_id,messages\n")
        ->and(substr_count($attention->streamedContent(), "\n"))->toBe(1 + 4 + 2);
});

it('answers not found for a run Teme doesn’t have, or a file it doesn’t make', function (): void {
    $missing = strtolower((string) Str::ulid());

    $this->actingAs($this->admin)->get("/admin/tools/import/runs/{$missing}/everything")->assertNotFound();
    $this->actingAs($this->admin)->get("/admin/tools/import/runs/{$this->run}/everyone")->assertNotFound();
});

it('refuses the report and its files to someone who may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get($this->show)->assertForbidden();
    $this->actingAs($editor)->get("/admin/tools/import/runs/{$this->run}/everything")->assertForbidden();
});
