<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Operations\AddField;
use App\Domain\Fields\Operations\Input\AddFieldInput;
use App\Domain\Fields\Operations\Input\MoveFieldInput;
use App\Domain\Fields\Operations\Input\RemoveFieldInput;
use App\Domain\Fields\Operations\Input\RestoreFieldInput;
use App\Domain\Fields\Operations\Input\UpdateFieldInput;
use App\Domain\Fields\Operations\MoveField;
use App\Domain\Fields\Operations\RemoveField;
use App\Domain\Fields\Operations\RestoreField;
use App\Domain\Fields\Operations\UpdateField;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Site\ArticleViews;
use App\Domain\Types\TypeAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBO_A = '01K9F1E1D0000000000000AA01';
const FBO_B = '01K9F1E1D0000000000000BB02';
const FBO_C = '01K9F1E1D0000000000000CC03';
const FBO_RUNTIME = '01K9F1E1D0000000000000RT02';
const FBO_RATING = '01K9F1E1D0000000000000RA03';
const FBO_TRAILER = '01K9F1E1D0000000000000TR04';

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    StoredFieldRows::movieType();
});

/**
 * A field as the sheet sends it: Text tagline on movies, with no key yet,
 * unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function fboDefinition(array $overrides = []): FieldDefinition
{
    return FieldDefinition::fromArray(StoredFieldRows::entry('', 'tagline', $overrides));
}

/**
 * The stored field with this key, as the sheet would send it back with these changes.
 *
 * @param  array<string, mixed>  $changes
 */
function fboEdited(string $key, array $changes): FieldDefinition
{
    $current = app(StoredFields::class)->find($key);
    assert($current !== null);

    return FieldDefinition::fromArray([...$current->toArray(), 'key' => '', ...$changes]);
}

/** @return array<string, int> the type's live keys and positions, in position order */
function fboPositions(): array
{
    return DB::table(StoredFields::TABLE)->where('content_type', 'movie')->whereNull('removed_at')
        ->orderBy('position')->pluck('position', 'key')->map(fn (mixed $p): int => (int) $p)->all();
}

/** @return string the table as it stands, for a comparison */
function fboTable(): string
{
    return DB::table(StoredFields::TABLE)->orderBy('key')->get()->toJson();
}

/** Three Text fields a, b and c at 1, 2 and 3. */
function fboThree(): void
{
    StoredFieldRows::store(StoredFieldRows::entry(FBO_A, 'a'), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_B, 'b'), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_C, 'c'), 3);
}

/**
 * A movie draft holding these values, saved as an editor saves it.
 *
 * @param  array<string, mixed>  $fields
 */
function fboMovie(User $author, array $fields): Content
{
    $movie = lifecycleDraft($author, 'Arrival', 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $author->id));

    return $movie->refresh();
}

it('adds a field last, with a new key, live at once', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_A, 'tagline'), 1);

    $key = app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['handle' => 'runtime', 'label' => 'Runtime', 'fieldType' => 'number']), (string) $this->admin->id));

    expect($key)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(last(app(FieldRegistry::class)->forType('movie'))->handle)->toBe('runtime')
        ->and(fboPositions())->toBe([FBO_A => 1, $key => 2]);
});

it('refuses a handle another field on the type has, or a removed field holding values still has', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_TRAILER, 'trailer_url', ['fieldType' => 'url']), 2);
    fboMovie($this->editor, [FBO_TRAILER => 'https://a.test/t']);
    StoredFieldRows::remove(FBO_TRAILER);
    $before = fboTable();

    expect(fn () => app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['handle' => 'runtime']), (string) $this->admin->id)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['handle' => ['Movies already have a field called runtime.']]))
        ->and(fn () => app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['handle' => 'trailer_url']), (string) $this->admin->id)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['handle' => ['A removed field called trailer_url still holds values on 1 movies. Delete them, or choose another handle.']]))
        ->and(fboTable())->toBe($before);
});

it('updates a field’s label, handle and settings, keeping every value', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    $movie = fboMovie($this->editor, [FBO_RUNTIME => 116]);
    listsPublish($movie, $this->editor);

    $result = app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_RUNTIME, fboEdited(FBO_RUNTIME, ['handle' => 'running_time', 'label' => 'Running time', 'help' => 'In minutes.']), (string) $this->admin->id));
    $view = app(ArticleViews::class)->published($movie->refresh());

    expect($result)->toBe(['changed' => true])
        ->and(app(FieldRegistry::class)->find(FBO_RUNTIME)?->label)->toBe('Running time')
        ->and(app(FieldRegistry::class)->find(FBO_RUNTIME)?->description)->toBe('In minutes.')
        ->and($view->field('running_time')->value)->toBe(116)
        ->and($view->field('runtime'))->toBeNull()
        ->and(fboPositions())->toBe([FBO_RUNTIME => 1]);
});

it('refuses to rename a field to a handle another live field of the type holds, and writes nothing (#323)', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RATING, 'rating'), 2);
    $before = fboTable();

    expect(fn () => app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_RUNTIME, fboEdited(FBO_RUNTIME, ['handle' => 'rating']), (string) $this->admin->id)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['handle' => ['Movies already have a field called rating.']]))
        ->and(fboTable())->toBe($before);
});

it('says nothing changed when a Choice is saved as it is', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RATING, 'rating', ['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]]]), 1);
    $before = fboTable();

    $result = app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_RATING, fboEdited(FBO_RATING, ['settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]]]), (string) $this->admin->id));

    expect($result)->toBe(['changed' => false])
        ->and(fboTable())->toBe($before);
});

it('refuses to change a field’s type in place, naming the two-field way', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    $before = fboTable();

    expect(fn () => app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_RUNTIME, fboEdited(FBO_RUNTIME, ['fieldType' => 'text']), (string) $this->admin->id)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['fieldType' => ['Can’t change: stored values would be read as another kind. To change it, add a new field and remove this one.']]))
        ->and(fboTable())->toBe($before);
});

it('refuses a key that is not a live field of the type', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_A, 'a'), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_B, 'b', ['type' => 'post']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_C, 'c'), 2, removed: true);
    $before = fboTable();
    $nope = fn (string $key) => fn (ValidationException $e) => expect($e->errors())->toBe(['key' => ["There is no field {$key}."]]);

    expect(fn () => app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_B, fboEdited(FBO_B, ['type' => 'movie']), (string) $this->admin->id)))->toThrow($nope(FBO_B))
        ->and(fn () => app(MoveField::class)->execute(new MoveFieldInput('movie', FBO_C, 1, (string) $this->admin->id)))->toThrow($nope(FBO_C))
        ->and(fn () => app(RemoveField::class)->execute(new RemoveFieldInput('movie', FBO_RUNTIME, (string) $this->admin->id)))->toThrow($nope(FBO_RUNTIME))
        ->and(fn () => app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_A, (string) $this->admin->id)))->toThrow($nope(FBO_A))
        ->and(fboTable())->toBe($before);
});

it('moves a field and renumbers the rest', function (): void {
    fboThree();
    $move = fn (string $key, int $position): array => app(MoveField::class)->execute(new MoveFieldInput('movie', $key, $position, (string) $this->admin->id));

    expect($move(FBO_C, 1))->toBe([FBO_C, FBO_A, FBO_B])
        ->and(fboPositions())->toBe([FBO_C => 1, FBO_A => 2, FBO_B => 3])
        ->and($move(FBO_C, 9))->toBe([FBO_A, FBO_B, FBO_C])
        ->and(fboPositions())->toBe([FBO_A => 1, FBO_B => 2, FBO_C => 3])
        ->and($move(FBO_C, 0))->toBe([FBO_C, FBO_A, FBO_B])
        ->and(array_map(fn (Field $f): string => $f->key, app(FieldRegistry::class)->stored('movie')))->toBe([FBO_C, FBO_A, FBO_B]);
});

it('numbers the rest 1..n after a remove and a restore, with no gap', function (): void {
    fboThree();

    app(RemoveField::class)->execute(new RemoveFieldInput('movie', FBO_B, (string) $this->admin->id));

    expect(fboPositions())->toBe([FBO_A => 1, FBO_C => 2]);

    app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_B, (string) $this->admin->id));

    expect(fboPositions())->toBe([FBO_A => 1, FBO_C => 2, FBO_B => 3]);
});

it('removes a field, keeping its values, and restores it last with them', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_TRAILER, 'trailer_url', ['fieldType' => 'url']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_RUNTIME, 'runtime', ['fieldType' => 'number']), 2);
    $movie = fboMovie($this->editor, [FBO_TRAILER => 'https://a.test/t', FBO_RUNTIME => 116]);
    listsPublish($movie, $this->editor);

    app(RemoveField::class)->execute(new RemoveFieldInput('movie', FBO_TRAILER, (string) $this->admin->id));
    $registry = app(FieldRegistry::class);

    expect(array_map(fn (Field $f): string => $f->handle, $registry->forType('movie')))->not->toContain('trailer_url')
        ->and($registry->removed('movie')[0]->key)->toBe(FBO_TRAILER)
        ->and($registry->removed('movie')[0]->removedBy)->toBe((int) $this->admin->id)
        ->and(fboStoredValue($movie, FBO_TRAILER))->toBe('https://a.test/t');

    app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_TRAILER, (string) $this->admin->id));
    $view = app(ArticleViews::class)->published($movie->refresh());

    expect(last($registry->forType('movie'))->handle)->toBe('trailer_url')
        ->and($registry->removed('movie'))->toBe([])
        ->and($view->field('trailer_url')->value)->toBe('https://a.test/t');
});

/** The value the movie's working revision holds under this key. */
function fboStoredValue(Content $movie, string $key): mixed
{
    $fields = DB::table('content_revisions')->where('id', $movie->refresh()->working_revision_id)->value('fields');

    return json_decode((string) $fields, true)[$key] ?? null;
}

it('refuses to restore a field whose handle a live field now has', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FBO_A, 'tagline'), 1, removed: true);
    StoredFieldRows::store(StoredFieldRows::entry(FBO_B, 'tagline'), 1);
    $before = fboTable();

    expect(fn () => app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_A, (string) $this->admin->id)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['handle' => ['A field called tagline is on Movies now. Rename one of them first.']]))
        ->and(fboTable())->toBe($before);
});

it('rebuilds the type’s search when a searchable field is added, removed or restored, or its search changes', function (): void {
    Queue::fake();
    $actor = (string) $this->admin->id;

    $number = app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['handle' => 'runtime', 'fieldType' => 'number']), $actor));
    Queue::assertNothingPushed();

    $key = app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['search' => true]), $actor));
    Queue::assertPushed(ReindexSearchJob::class, 1);

    app(RemoveField::class)->execute(new RemoveFieldInput('movie', $key, $actor));
    Queue::assertPushed(ReindexSearchJob::class, 2);

    app(RestoreField::class)->execute(new RestoreFieldInput('movie', $key, $actor));
    Queue::assertPushed(ReindexSearchJob::class, 3);

    app(UpdateField::class)->execute(new UpdateFieldInput('movie', $key, fboEdited($key, ['search' => false]), $actor));
    Queue::assertPushed(ReindexSearchJob::class, 4);

    app(MoveField::class)->execute(new MoveFieldInput('movie', $number, 2, $actor));
    app(RemoveField::class)->execute(new RemoveFieldInput('movie', $number, $actor));
    Queue::assertPushed(ReindexSearchJob::class, 4);
});

it('empties the page cache after a field changes, and not after one that rolls back', function (): void {
    $folder = cacheOn();
    cachePage('/about/');

    cacheRolledBack(fn () => app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(), (string) $this->admin->id)));

    expect(cacheKept('/about/'))->toBe(['/about/'])
        ->and(app(FieldRegistry::class)->stored('movie'))->toBe([]);

    app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(), (string) $this->admin->id));

    expect(cacheKept('/about/'))->toBe([]);

    cacheOff($folder);
});

it('refuses every change while the types are locked', function (): void {
    fboThree();
    StoredFieldRows::remove(FBO_C);
    config(['teme.types_locked' => true]);
    $before = fboTable();
    $actor = (string) $this->admin->id;
    $locked = fn (AuthorizationException $e) => expect($e->getMessage())->toBe(TypeAccess::LOCKED);

    expect(fn () => app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(), $actor)))->toThrow($locked)
        ->and(fn () => app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_A, fboEdited(FBO_A, ['label' => 'X']), $actor)))->toThrow($locked)
        ->and(fn () => app(MoveField::class)->execute(new MoveFieldInput('movie', FBO_B, 1, $actor)))->toThrow($locked)
        ->and(fn () => app(RemoveField::class)->execute(new RemoveFieldInput('movie', FBO_A, $actor)))->toThrow($locked)
        ->and(fn () => app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_C, $actor)))->toThrow($locked)
        ->and(fboTable())->toBe($before);
});

it('writes nothing when authorization fails', function (Closure $run): void {
    fboThree();
    StoredFieldRows::store(StoredFieldRows::entry(FBO_TRAILER, 'trailer_url', ['fieldType' => 'url', 'search' => false]), 4, removed: true);
    $before = [fboTable(), Cache::get(StoredFields::CACHE_KEY)];
    Queue::fake();

    expect(fn () => $run($this->editor))->toThrow(AuthorizationException::class)
        ->and([fboTable(), Cache::get(StoredFields::CACHE_KEY)])->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'AddField' => [fn (User $u) => app(AddField::class)->execute(new AddFieldInput('movie', fboDefinition(['search' => true]), (string) $u->id))],
    'UpdateField' => [fn (User $u) => app(UpdateField::class)->execute(new UpdateFieldInput('movie', FBO_A, fboEdited(FBO_A, ['label' => 'Changed', 'search' => true]), (string) $u->id))],
    'MoveField' => [fn (User $u) => app(MoveField::class)->execute(new MoveFieldInput('movie', FBO_C, 1, (string) $u->id))],
    'RemoveField' => [fn (User $u) => app(RemoveField::class)->execute(new RemoveFieldInput('movie', FBO_A, (string) $u->id))],
    'RestoreField' => [fn (User $u) => app(RestoreField::class)->execute(new RestoreFieldInput('movie', FBO_TRAILER, (string) $u->id))],
]);
