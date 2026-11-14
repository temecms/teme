<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\RebuildImportedCountsInput;
use App\Domain\Contents\Operations\RebuildImportedCounts;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\Finish\RecountStep;
use App\Domain\Import\Phases\ImportPhases;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
    ContentImports::import(ContentImports::item($this->author), $this->admin);
    // Drift, as a lost delta would leave it.
    DB::table('content_counts')->where('bucket', 'published')->where('author_id', 0)->update(['count' => 99]);
});

function ricPublished(): int
{
    return (int) DB::table('content_counts')->where('bucket', 'published')->where('author_id', 0)->sum('count');
}

it('fixes counts that drifted, with teme:counts:rebuild’s own reckoning (I-141)', function (): void {
    $result = app(RebuildImportedCounts::class)->execute(new RebuildImportedCountsInput((string) $this->admin->id));

    expect($result['fixed'])->toBeTrue()
        ->and($result['drifted'])->toBeGreaterThan(0)
        ->and(ricPublished())->toBe(1);
});

it('checks the counts at the end of every run, at 1210', function (): void {
    $step = app(ImportPhases::class)->find('recount');

    expect($step)->toBeInstanceOf(RecountStep::class)
        ->and($step?->order())->toBe(1210)
        ->and($step?->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step?->runsIn(ImportRunKind::CatchUp))->toBeTrue();

    importFake();
    $source = importSource();
    importPhaseAlone(app(RecountStep::class));
    // A person's run: their queued jobs bind no elevation (I-392).
    importRun($source, $this->admin);

    expect(ricPublished())->toBe(1);
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn (): array => app(RebuildImportedCounts::class)->execute(new RebuildImportedCountsInput((string) $editor->id)))->toThrow(AuthorizationException::class)
        ->and(ricPublished())->toBe(99);
});
