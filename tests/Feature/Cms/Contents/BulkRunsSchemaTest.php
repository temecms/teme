<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\ContentListFilters;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Jobs\BulkRuns;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('keeps a run of its own beside its tracked job', function (): void {
    expect(Schema::hasColumns('bulk_runs', ['id', 'tracked_job_id', 'user_id', 'content_type', 'action', 'filters', 'after', 'done', 'skipped', 'refusal', 'created_at', 'updated_at']))->toBeTrue();
});

it('refuses a run its checks don’t allow, naming the check', function (array $changes, string $check): void {
    expect(fn () => BulkRuns::run($this->editor, $changes))->toThrow(QueryException::class, $check);
})->with([
    'an action it doesn’t know' => [['action' => 'publish'], 'bulk_runs_action_check'],
    'filters as a list' => [['filters' => '[]'], 'bulk_runs_filters_check'],
    'skips as a list' => [['skipped' => '[]'], 'bulk_runs_skipped_check'],
    'less than nothing done' => [['done' => -1], 'bulk_runs_done_check'],
]);

it('goes when its tracked job is pruned', function (): void {
    $run = BulkRuns::run($this->editor);

    DB::table('tracked_jobs')->where('id', DB::table('bulk_runs')->where('id', $run)->value('tracked_job_id'))->delete();

    expect(DB::table('bulk_runs')->where('id', $run)->exists())->toBeFalse();
});

it('goes with its person’s account', function (): void {
    $run = BulkRuns::run($this->editor);

    $this->editor->delete();

    expect(DB::table('bulk_runs')->where('id', $run)->exists())->toBeFalse();
});

it('reads a list’s address as the list screen does, keeping only the keys the type’s list knows', function (): void {
    $news = taxonomyTerm('category', 'News');
    $posts = app(ContentTypeRegistry::class)->get('post');

    $filters = ContentListFilters::read([
        'tab' => 'drafts',
        'search' => 'harbour',
        'category' => $news->id,
        'month' => '2026-04',
        'author' => '',
        'bogus' => 'x',
        'in' => ['body'],
    ], $posts, 'Africa/Lagos');

    $request = $filters->request($posts, $this->editor->id, 100, after: 'cursor');

    expect($filters->values)->toBe(['tab' => 'drafts', 'search' => 'harbour', 'category' => $news->id, 'month' => '2026-04'])
        ->and($filters->tab())->toBe('drafts')
        ->and($request->terms)->toBe(['category' => $news->id])
        ->and($request->searchIn)->toBe('title')
        ->and($request->startsAt)->toBe('2026-03-31T23:00:00+00:00')
        ->and($request->endsBefore)->toBe('2026-04-30T23:00:00+00:00')
        ->and($request->viewerId)->toBe($this->editor->id)
        ->and($request->perPage)->toBe(100)
        ->and($request->after)->toBe('cursor')
        ->and(ContentListFilters::read(['sort' => 'title'], $posts, 'UTC')->tab())->toBe('all');
});
