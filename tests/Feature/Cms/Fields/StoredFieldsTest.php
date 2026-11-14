<?php

declare(strict_types=1);

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldValues;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FB_RUNTIME = '01K9F1E1D0000000000000RT02';
const FB_RELEASE = '01K9F1E1D0000000000000RD01';
const FB_TRAILER = '01K9F1E1D0000000000000TR04';

/** @return list<string> */
function fbHandles(string $type): array
{
    return array_map(fn (Field $field): string => $field->handle, app(FieldRegistry::class)->forType($type));
}

it('gives a type its stored fields after code’s, in their order, and leaves removed ones out', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'runtime', ['fieldType' => 'number']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FB_RELEASE, 'release_date', ['fieldType' => 'datetime', 'settings' => ['dateOnly' => true]]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FB_TRAILER, 'trailer_url', ['fieldType' => 'url']), 3, removed: true);

    expect(fbHandles('movie'))->toBe(['release_date', 'runtime'])
        ->and(array_map(fn (FieldDefinition $d): string => $d->handle, app(FieldRegistry::class)->removed('movie')))->toBe(['trailer_url'])
        ->and(app(FieldRegistry::class)->storedKeys('movie'))->toHaveCount(3)
        ->and(app(FieldRegistry::class)->find(FB_RELEASE)?->stored)->toBeTrue()
        ->and(app(FieldRegistry::class)->find(FB_TRAILER))->toBeNull();
});

it('puts stored fields on Posts after the built-ins', function (): void {
    $before = fbHandles('post');
    StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'subtitle', ['type' => 'post']), 1);

    $after = fbHandles('post');

    expect($before)->toHaveCount(17)
        ->and(array_slice($after, 0, 17))->toBe($before)
        ->and(last($after))->toBe('subtitle');
});

it('reads no stored field at boot, and keeps them out of the generated reference', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'subtitle', ['type' => 'post']), 1);
    $registry = app(FieldRegistry::class);

    expect(array_map(fn (Field $f): string => $f->key, $registry->all()))->not->toContain(FB_RUNTIME)
        ->and(array_map(fn (Field $f): string => $f->key, $registry->forDeclaration(app(ContentTypeRegistry::class)->get('post'))))->not->toContain(FB_RUNTIME);
});

it('sees a field another process stored at its next request', function (): void {
    StoredFieldRows::movieType();
    expect(fbHandles('movie'))->toBe([]);

    app(StoredFields::class)->insert(FieldDefinition::fromArray(StoredFieldRows::entry(FB_RUNTIME, 'runtime'))->withPosition(1));
    app(StoredFields::class)->remember();

    expect(fbHandles('movie'))->toBe([]);

    event(new RequestHandled(Request::create('/'), new Response));

    expect(fbHandles('movie'))->toBe(['runtime']);
});

it('leaves out a row that won’t read, reports it, and keeps the others', function (): void {
    Exceptions::fake();
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'runtime'), 1);
    DB::table(StoredFields::TABLE)->insert([
        'key' => FB_RELEASE, 'content_type' => 'movie', 'handle' => 'hologram', 'position' => 2,
        'definition' => json_encode(['label' => 'Hologram', 'fieldType' => 'hologram']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(StoredFields::class)->remember();
    app(FieldRegistry::class)->refreshStored();

    expect(fbHandles('movie'))->toBe(['runtime']);
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('counts the items holding a value in the working revision, the live copy or the autosave, trashed ones too', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('editor');
    $key = FB_RUNTIME;
    $holds = json_encode([$key => 'x']);
    [$inWorking, $inLive, $inAutosave, $trashed, $without] = array_map(fn (int $i) => lifecycleDraft($author, "Item {$i}"), range(1, 5));

    DB::table('content_revisions')->where('id', $inWorking->working_revision_id)->update(['fields' => $holds]);
    DB::table('contents')->where('id', $inLive->id)->update(['fields' => $holds]);
    DB::table('content_autosaves')->insert([
        'id' => (string) Str::ulid(), 'content_id' => $inAutosave->id, 'user_id' => $author->id, 'title' => 'Item 3',
        'document' => '{}', 'fields' => $holds, 'terms' => '[]', 'saved_at' => now(),
    ]);
    DB::table('content_revisions')->where('id', $trashed->working_revision_id)->update(['fields' => $holds]);
    DB::table('contents')->where('id', $trashed->id)->update(['deleted_at' => now()]);

    expect(FieldValues::counts('post', [$key, '01K9F1E1D0000000000000NONE']))->toBe([$key => 4])
        ->and(FieldValues::counts('page', [$key]))->toBe([])
        ->and(FieldValues::split('post', $key))->toBe(['published' => 0, 'unpublished' => 4])
        ->and($without->id)->not->toBeEmpty();
});

it('deletes a type’s fields with the type', function (): void {
    $admin = ctAdmin();
    ctCreate($admin, ['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'segment' => 'recipes']);
    StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'serves', ['type' => 'recipe', 'fieldType' => 'number']), 1);
    expect(last(fbHandles('recipe')))->toBe('serves');

    app(DeleteType::class)->execute(new DeleteTypeInput('recipe', (string) $admin->id));

    expect(DB::table(StoredFields::TABLE)->count())->toBe(0)
        ->and(app(FieldRegistry::class)->storedKeys('recipe'))->toBe([])
        ->and(fbHandles('recipe'))->toBe([]);
});

it('never moves a row to another type when it updates one', function (): void {
    StoredFieldRows::movieType();
    $stored = StoredFieldRows::store(StoredFieldRows::entry(FB_RUNTIME, 'runtime', ['fieldType' => 'number']), 1);

    app(StoredFields::class)->update(FieldDefinition::fromArray([...$stored->toArray(), 'type' => 'post', 'label' => 'Running time'])->withPosition(1));

    expect(DB::table(StoredFields::TABLE)->where('key', FB_RUNTIME)->value('content_type'))->toBe('movie')
        ->and(app(StoredFields::class)->find(FB_RUNTIME)?->label)->toBe('Running time');
});
