<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Operations\Input\MoveFieldInput;
use App\Domain\Fields\Operations\MoveField;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBF_RELEASE = '01K9F1E1D0000000000000RD01';
const FBF_RUNTIME = '01K9F1E1D0000000000000RT02';
const FBF_TRAILER = '01K9F1E1D0000000000000TR04';
const FBF_POSTER = '01K9F1E1D0000000000000PS05';
const FBF_SUBTITLE = '01K9F1E1D0000000000000ST06';

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->import = fn (array $file, ?User $actor = null): array => app(ImportTypes::class)->execute(new ImportTypesInput($file, (string) ($actor ?? $this->admin)->id));
});

/** The type movie as a file entry. */
function fbfMovie(): array
{
    return ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'taxonomies' => [], 'supports' => []])->toArray();
}

/** The movie fields the file brings: a date-only release date and a runtime. */
function fbfFields(): array
{
    return [
        StoredFieldRows::entry(FBF_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]),
        StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'number']),
    ];
}

/** @return array<string, string> the table's rows, key => position:handle:definition */
function fbfRows(): array
{
    return DB::table(StoredFields::TABLE)->orderBy('key')->get()
        ->mapWithKeys(fn (object $row): array => [$row->key => $row->position.':'.$row->handle.':'.$row->definition.':'.($row->removed_at ?? '')])->all();
}

it('exports a site’s live fields, each with its type, in order', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'number']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_RELEASE, 'release_date'), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_TRAILER, 'trailer_url', ['fieldType' => 'url']), 3, removed: true);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_SUBTITLE, 'subtitle', ['type' => 'post']), 1);

    $fields = TypesFile::export()['fields'];

    expect(array_map(fn (array $f): string => $f['type'].'.'.$f['handle'], $fields))->toBe(['movie.release_date', 'movie.runtime', 'post.subtitle'])
        ->and($fields[1])->toBe(StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'number']))
        ->and(json_decode(TypesFile::json(), true)['fields'][2]['key'])->toBe(FBF_SUBTITLE);
});

it('brings fields in with their keys, so values imported later land in them', function (): void {
    $report = ($this->import)(ctFile([fbfMovie()], ['fields' => fbfFields()]));

    expect(DB::table(StoredFields::TABLE)->orderBy('position')->pluck('position', 'key')->all())->toBe([FBF_RELEASE => 1, FBF_RUNTIME => 2])
        ->and($report['fieldsAdded'])->toBe(['movie.release_date', 'movie.runtime'])
        ->and(array_map(fn (Field $f): string => $f->key, app(FieldRegistry::class)->forType('movie')))->toBe([FBF_RELEASE, FBF_RUNTIME]);
});

it('updates a field the file changes, restores one removed here, and leaves one only here in place', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_RELEASE, 'release_date', ['label' => 'Released']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_TRAILER, 'trailer_url', ['fieldType' => 'url']), 2, removed: true);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_POSTER, 'poster', ['fieldType' => 'media']), 3);
    $poster = fbfRows()[FBF_POSTER];

    $report = ($this->import)(ctFile([fbfMovie()], ['fields' => [
        StoredFieldRows::entry(FBF_RELEASE, 'release_date', ['label' => 'Release date']),
        StoredFieldRows::entry(FBF_TRAILER, 'trailer_url', ['fieldType' => 'url']),
    ]]));

    expect($report['fieldsUpdated'])->toBe(['movie.release_date', 'movie.trailer_url'])
        ->and($report['fieldsOnlyHere'])->toBe(['movie.poster'])
        ->and(app(FieldRegistry::class)->find(FBF_TRAILER)?->handle)->toBe('trailer_url')
        ->and(app(FieldRegistry::class)->find(FBF_RELEASE)?->label)->toBe('Release date')
        ->and(fbfRows()[FBF_POSTER])->toBe($poster);

    expect(($this->import)(ctFile([fbfMovie()], ['fields' => [
        StoredFieldRows::entry(FBF_RELEASE, 'release_date', ['label' => 'Release date']),
        StoredFieldRows::entry(FBF_TRAILER, 'trailer_url', ['fieldType' => 'url']),
    ]]))['fieldsUnchanged'])->toBe(['movie.release_date', 'movie.trailer_url']);
});

it('refuses a file that changes a field’s type in place, or a handle a removed field holding values still has', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBF_TRAILER, 'trailer_url', ['fieldType' => 'url']), 2);
    $editor = User::factory()->create()->assignRole('editor');
    $movie = lifecycleDraft($editor, 'Arrival', 'movie');
    DB::table('content_revisions')->where('id', $movie->working_revision_id)->update(['fields' => json_encode([FBF_TRAILER => 'https://a.test/t'])]);
    StoredFieldRows::remove(FBF_TRAILER);
    $before = fbfRows();

    expect(fn () => ($this->import)(ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'text'])]])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.0.fieldType'])->toBe(['Can’t change: stored values would be read as another kind. To change it, add a new field and remove this one.']))
        ->and(fn () => ($this->import)(ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_POSTER, 'trailer_url', ['fieldType' => 'url'])]])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.0.handle'])->toBe(['A removed field called trailer_url still holds values on 1 movies. Delete them, or choose another handle.']))
        ->and(fbfRows())->toBe($before)
        ->and(DB::table('content_type_definitions')->count())->toBe(1);
});

it('refuses a file that moves a field to another type, writes nothing, and the movie still saves its value', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);
    $editor = User::factory()->create()->assignRole('editor');
    $movie = lifecycleDraft($editor, 'Arrival', 'movie');
    $movie = app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], [FBF_RUNTIME => 116], $movie->working_revision_id, (string) $editor->id));
    $before = fbfRows();
    $types = DB::table('content_type_definitions')->get()->all();

    expect(fn () => ($this->import)(ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['type' => 'post', 'fieldType' => 'number'])]])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['fields.0.type' => ['Field 1: A field can’t move to another type once it is made: runtime belongs to movies.']]))
        ->and(fbfRows())->toBe($before)
        ->and(DB::table(StoredFields::TABLE)->where('key', FBF_RUNTIME)->value('content_type'))->toBe('movie')
        ->and(DB::table('content_type_definitions')->get()->all())->toEqual($types)
        ->and(app(FieldRegistry::class)->storedKeys('movie'))->toBe([FBF_RUNTIME]);

    $movie = $movie->refresh();
    $saved = app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], [FBF_RUNTIME => 120], $movie->working_revision_id, (string) $editor->id));

    expect((array) ContentRevision::query()->findOrFail($saved->working_revision_id)->fields)->toBe([FBF_RUNTIME => 120]);
});

it('refuses a field for a type neither the file nor the site has', function (): void {
    expect(fn () => ($this->import)(ctFile([], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['type' => 'ghost'])]])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.0.type'])->toBe(['Field 1: the file has no type ghost, and this site hasn’t either.']));
});

it('refuses a file naming one key twice, two fields with one handle, or a field that breaks a rule', function (): void {
    $twice = ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'runtime'), StoredFieldRows::entry(FBF_RUNTIME, 'running')]]);
    $handles = ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'runtime'), StoredFieldRows::entry(FBF_RELEASE, 'runtime')]]);
    $rules = ctFile([fbfMovie()], ['fields' => [StoredFieldRows::entry(FBF_RUNTIME, 'excerpt', ['label' => ''])]]);

    expect(fn () => ($this->import)($twice))->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.1.key'])->toBe(['Field 2: the file has the key '.FBF_RUNTIME.' twice.']))
        ->and(fn () => ($this->import)($handles))->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.1.handle'])->toBe(['Field 2: movie has two fields called runtime in the file.']))
        ->and(fn () => ($this->import)($rules))->toThrow(fn (ValidationException $e) => expect($e->errors()['fields.0'])->toBe(['Field 1: Give the field a label. excerpt is one of Teme’s own fields, so a field of yours can’t use it.']))
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});

it('writes nothing when a person without types.manage imports', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->import)(ctFile([fbfMovie()], ['fields' => fbfFields()]), $editor))->toThrow(AuthorizationException::class)
        ->and(DB::table(StoredFields::TABLE)->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});

it('prints what it did with the fields', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'types');
    file_put_contents($path, json_encode(ctFile([fbfMovie()], ['fields' => fbfFields()])));
    StoredFieldRows::store(StoredFieldRows::entry(FBF_SUBTITLE, 'subtitle', ['type' => 'post']), 1);

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Added field movie.release_date (Release date).')
        ->expectsOutput('Added field movie.runtime (Runtime).')
        ->expectsOutput('Left in place, not in the file: post.subtitle. An import never removes a field.')
        ->assertSuccessful();

    file_put_contents($path, json_encode(ctFile([fbfMovie()], ['fields' => [fbfFields()[0], StoredFieldRows::entry(FBF_RUNTIME, 'runtime', ['label' => 'Running time', 'fieldType' => 'number'])]])));

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Updated field movie.runtime (Running time).')
        ->expectsOutput('Fields unchanged: movie.release_date.')
        ->assertSuccessful();
    unlink($path);
});

it('numbers a type’s fields 1..n after an import that leaves a field only here, and a move then lands where asked', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_POSTER, 'poster', ['fieldType' => 'media']), 1);
    StoredFieldRows::store(fbfFields()[0], 2);
    StoredFieldRows::store(fbfFields()[1], 3);
    $positions = fn (): array => DB::table(StoredFields::TABLE)->whereNull('removed_at')->orderBy('position')->pluck('position', 'handle')->map(fn (mixed $p): int => (int) $p)->all();

    ($this->import)(ctFile([fbfMovie()], ['fields' => fbfFields()]));

    expect($positions())->toBe(['release_date' => 1, 'runtime' => 2, 'poster' => 3])
        ->and(($this->import)(ctFile([fbfMovie()], ['fields' => fbfFields()]))['fieldsUnchanged'])->toBe(['movie.release_date', 'movie.runtime']);

    app(MoveField::class)->execute(new MoveFieldInput('movie', FBF_POSTER, 1, (string) $this->admin->id));

    expect($positions())->toBe(['poster' => 1, 'release_date' => 2, 'runtime' => 3]);
});

it('imports a file that swaps two fields’ handles, each ending with the other’s', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(fbfFields()[0], 1);
    StoredFieldRows::store(fbfFields()[1], 2);

    $report = ($this->import)(ctFile([fbfMovie()], ['fields' => [
        StoredFieldRows::entry(FBF_RELEASE, 'runtime', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]),
        StoredFieldRows::entry(FBF_RUNTIME, 'release_date', ['label' => 'Runtime', 'fieldType' => 'number']),
    ]]));

    expect(DB::table(StoredFields::TABLE)->orderBy('key')->pluck('handle', 'key')->all())->toBe([FBF_RELEASE => 'runtime', FBF_RUNTIME => 'release_date'])
        ->and($report['fieldsUpdated'])->toBe(['movie.runtime', 'movie.release_date'])
        ->and(app(FieldRegistry::class)->find(FBF_RELEASE)?->handle)->toBe('runtime');
});

it('imports a new field taking a handle a later entry renames away', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(fbfFields()[1], 1);

    $report = ($this->import)(ctFile([fbfMovie()], ['fields' => [
        StoredFieldRows::entry(FBF_POSTER, 'runtime', ['fieldType' => 'media']),
        StoredFieldRows::entry(FBF_RUNTIME, 'running_time', ['label' => 'Runtime', 'fieldType' => 'number']),
    ]]));

    expect($report['fieldsAdded'])->toBe(['movie.runtime'])
        ->and($report['fieldsUpdated'])->toBe(['movie.running_time'])
        ->and(DB::table(StoredFields::TABLE)->where('handle', 'like', 'tmp\_%')->count())->toBe(0)
        ->and(DB::table(StoredFields::TABLE)->orderBy('position')->pluck('handle', 'key')->all())->toBe([FBF_POSTER => 'runtime', FBF_RUNTIME => 'running_time']);
});

it('refuses a file entry taking the handle of a field only here, before writing any field', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBF_POSTER, 'poster', ['fieldType' => 'media']), 1);
    StoredFieldRows::store(fbfFields()[0], 2);
    $before = fbfRows();

    expect(fn () => ($this->import)(ctFile([fbfMovie()], ['fields' => [
        StoredFieldRows::entry(FBF_RELEASE, 'released', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]),
        StoredFieldRows::entry(FBF_RUNTIME, 'poster', ['fieldType' => 'number']),
    ]])))->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['fields.1.handle' => ['Movies already have a field called poster.']]))
        ->and(fbfRows())->toBe($before);
});
