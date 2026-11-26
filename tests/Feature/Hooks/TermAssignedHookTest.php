<?php

declare(strict_types=1);

use App\Domain\Contents\ItemTerms;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Taxonomy\TermAssigned;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->nobody = User::factory()->create();
    $this->news = taxonomyTerm('category', 'News');
    $this->harbour = taxonomyTerm('category', 'Harbour');
    $this->owls = taxonomyTerm('tag', 'Owls');
});

/**
 * Records each TermAssigned the dispatcher delivers, with the open transaction
 * depth when a listener runs.
 *
 * @return ArrayObject<int, array{payload: TermAssigned, depth: int}>
 */
function termAssignedRecord(): ArrayObject
{
    $heard = new ArrayObject;

    app(HookDispatcher::class)->subscriber('core')->on(TermAssigned::class, function (TermAssigned $payload) use ($heard): void {
        $heard[] = ['payload' => $payload, 'depth' => DB::transactionLevel()];
    });

    return $heard;
}

/**
 * A draft of a post, filed under the given term IDs, through the real Operation.
 *
 * @param  list<string>  $terms
 */
function assignedDraft(User $author, array $terms): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Pictured', ['type' => 'doc', 'content' => []], [], (string) $author->id, $terms));
}

it('sends one TermAssigned for each taxonomy an item gained terms in, once, after commit', function (): void {
    $base = DB::transactionLevel();
    $heard = termAssignedRecord();

    $content = assignedDraft($this->editor, [(string) $this->news->id, (string) $this->owls->id]);

    expect($heard)->toHaveCount(2)
        ->and(array_map(fn (array $entry): string => $entry['payload']->taxonomy, $heard->getArrayCopy()))->toBe(['category', 'tag'])
        ->and($heard[0]['payload']->added)->toBe([(string) $this->news->id])
        ->and($heard[1]['payload']->added)->toBe([(string) $this->owls->id])
        ->and($heard[0]['payload']->removed)->toBe([])
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->item->id)->toBe((string) $content->id)
        ->and($heard[0]['payload']->actorId)->toBe((string) $this->editor->id);
});

it('sends TermAssigned with the terms added and taken off when a save swaps a category', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $heard = termAssignedRecord();

    app(SaveDraft::class)->execute(new SaveDraftInput(
        (string) $content->id,
        'Pictured',
        ['type' => 'doc', 'content' => []],
        [],
        (string) $content->working_revision_id,
        (string) $this->editor->id,
        [(string) $this->harbour->id],
    ));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['payload']->taxonomy)->toBe('category')
        ->and($heard[0]['payload']->added)->toBe([(string) $this->harbour->id])
        ->and($heard[0]['payload']->removed)->toBe([(string) $this->news->id]);
});

it('sends nothing when a save leaves the item’s terms as they were', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $heard = termAssignedRecord();

    app(SaveDraft::class)->execute(new SaveDraftInput(
        (string) $content->id,
        'Pictured again',
        ['type' => 'doc', 'content' => []],
        [],
        (string) $content->working_revision_id,
        (string) $this->editor->id,
        [(string) $this->news->id],
    ));

    expect($heard->getArrayCopy())->toBe([]);
});

it('sends TermAssigned when a details change alters the terms, and not when it does not', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $heard = termAssignedRecord();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        (string) $content->id,
        (string) $content->working_revision_id,
        (string) $this->editor->id,
        terms: [(string) $this->news->id, (string) $this->owls->id],
    ));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['payload']->taxonomy)->toBe('tag')
        ->and($heard[0]['payload']->added)->toBe([(string) $this->owls->id]);

    $content->refresh();
    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        (string) $content->id,
        (string) $content->working_revision_id,
        (string) $this->editor->id,
        terms: [(string) $this->news->id, (string) $this->owls->id],
    ));

    expect($heard)->toHaveCount(1);
});

it('sends no TermAssigned when a change is refused', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $heard = termAssignedRecord();

    expect(fn () => app(SaveDraft::class)->execute(new SaveDraftInput(
        (string) $content->id,
        'Nope',
        ['type' => 'doc', 'content' => []],
        [],
        (string) $content->working_revision_id,
        (string) $this->nobody->id,
        [(string) $this->harbour->id],
    )))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
            (string) $content->id,
            (string) $content->working_revision_id,
            (string) $this->nobody->id,
            terms: [(string) $this->owls->id],
        )))->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

it('sends no TermAssigned when the change rolls back after the terms were announced', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $heard = termAssignedRecord();

    $probe = new class extends BaseOperation
    {
        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): null
        {
            /** @var Content $content */
            $content = $input->content;
            ItemTerms::announce($content, [], [(string) test()->owls->id], null);

            throw new RuntimeException('perform() failed on purpose.');
        }
    };

    expect(fn () => $probe->execute(new class($content) implements OperationInput
    {
        public function __construct(public Content $content) {}

        public function actorId(): ?string
        {
            return null;
        }
    }))->toThrow(RuntimeException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

it('sends nothing from inside an import run, as no other item hook is sent from one', function (): void {
    $content = assignedDraft($this->editor, [(string) $this->news->id]);
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    $heard = termAssignedRecord();

    app(ImportMode::class)->during($run->id, fn () => app(SaveDraft::class)->execute(new SaveDraftInput(
        (string) $content->id,
        'Pictured',
        ['type' => 'doc', 'content' => []],
        [],
        (string) $content->working_revision_id,
        (string) $this->editor->id,
        [(string) $this->harbour->id],
    )));

    expect($heard->getArrayCopy())->toBe([]);
});
