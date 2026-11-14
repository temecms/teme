<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    aiFake();
    aiTask('tidy');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->content = lifecycleDraft($this->author, 'Harbour news');

    // A revision the assistant wrote: saved as a person saves, marked as the assistant's, and the request that made it settled as used.
    $this->assistant = function (array $changes = [], bool $settle = true): string {
        docSave($this->content, $this->author, docWith(docText('Shorter text.')));
        $revision = (string) $this->content->refresh()->working_revision_id;
        DB::table('content_revisions')->where('id', $revision)->update(['origin' => 'ai']);

        if ($settle) {
            $row = actvRow($this->author, ['subjectId' => $this->content->id, ...$changes]);
            app(SettleAiActivity::class)->execute(new SettleAiActivityInput($row, AiOutcome::Applied, $revision, (string) $this->author->id));
        }

        return $revision;
    };

    // The History pane's rows, as a viewer gets them.
    $this->history = fn (User $viewer): array => $this->actingAs($viewer)
        ->getJson(route('content.revisions.index', $this->content->id))
        ->assertOk()
        ->json('rows');

    $this->revisionOf = fn (array $rows, string $id): array => collect($rows)->firstWhere('id', $id);
});

it('marks a revision the assistant wrote with its option, and gives the author no way to the row (I-1307)', function (): void {
    $revision = ($this->assistant)(['variant' => 'shorter']);

    $rows = ($this->history)($this->author);
    $marked = ($this->revisionOf)($rows, $revision);

    expect($marked['assistant'])->toBe(['label' => 'Shorter', 'row' => null])
        ->and($marked['origin'])->toBe('ai')
        ->and(collect($rows)->where('id', '!=', $revision)->pluck('assistant')->filter()->all())->toBe([])
        ->and(collect($rows)->where('id', '!=', $revision)->pluck('origin')->unique()->all())->toBe(['human']);
});

it('gives the row\'s ID to those who hold ai-activity.view alone', function (): void {
    $revision = ($this->assistant)();
    $row = DB::table('ai_activity')->where('revision_id', $revision)->value('id');

    expect(($this->revisionOf)(($this->history)($this->admin), $revision)['assistant'])->toBe(['label' => 'Shorter', 'row' => $row])
        ->and(($this->revisionOf)(($this->history)($this->author), $revision)['assistant']['row'])->toBeNull();
});

it('names the task when the request had no option, and its key in words once the task has gone', function (string $task, string $label): void {
    $revision = ($this->assistant)(['task' => $task, 'variant' => null]);

    expect(($this->revisionOf)(($this->history)($this->author), $revision)['assistant']['label'])->toBe($label);
})->with([
    'a registered task' => ['tidy', 'Tidy'],
    'a task that has gone' => ['old-task', 'Old task'],
]);

it('leaves "Assistant" alone once the request has been pruned', function (): void {
    $revision = ($this->assistant)();

    $this->travelTo(now()->addMonths(13));
    $this->artisan('teme:ai-activity:prune')->assertSuccessful();

    expect(DB::table('ai_activity')->count())->toBe(0)
        ->and(($this->revisionOf)(($this->history)($this->author), $revision)['assistant'])->toBe(['label' => null, 'row' => null]);
});

it('treats a revision whose kind is the assistant\'s as the assistant\'s, whatever its origin', function (): void {
    docSave($this->content, $this->author, docWith(docText('Another text.')));
    $revision = (string) $this->content->refresh()->working_revision_id;
    DB::table('content_revisions')->where('id', $revision)->update(['kind' => 'ai']);

    expect(($this->revisionOf)(($this->history)($this->author), $revision)['assistant'])->toBe(['label' => null, 'row' => null]);
});

it('reads the record once for a page with an assistant revision, and not at all for one without', function (): void {
    docSave($this->content, $this->author, docWith(docText('A human edit.')));

    $reads = fn (): int => count(array_filter(
        planStatements(fn () => ($this->history)($this->author)),
        fn (array $entry): bool => str_contains($entry['query'], '"ai_activity"'),
    ));

    expect($reads())->toBe(0);

    ($this->assistant)();

    expect($reads())->toBe(1);
});

it('draws "Assistant" with its option, and links it only when given a row', function (): void {
    $panel = uiComponent('components/editor/rail/history-panel.tsx');

    expect($panel)->toContain('`Assistant · ${assistant.label}`')
        ->toContain("from '@/routes/tools/ai-activity'")
        ->toContain('aiActivity({ query: { row: assistant.row } })')
        ->toContain('assistant.row === null');
});
