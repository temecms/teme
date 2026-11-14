<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\TypesFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->own = ctDefinition(['permissions' => 'own'])->toArray();
    $this->note = ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'icon' => 'sticky-note', 'public' => false, 'archive' => false, 'searchable' => false, 'taxonomies' => [], 'supports' => [], 'comments' => null];
    $this->import = fn (array $file): array => scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput($file)));
});

it('exports each type’s own grants, every role with what it holds of the six, and none for a type that shares them', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    ctCreate($this->admin, $this->note);
    Role::findByName('author')->revokePermissionTo('film.publish');

    expect(TypesFile::export()['grants'])->toBe([
        ['type' => 'film', 'role' => 'administrator', 'abilities' => ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any']],
        ['type' => 'film', 'role' => 'editor', 'abilities' => ['create', 'edit.own', 'edit.any', 'publish', 'delete.any']],
        ['type' => 'film', 'role' => 'author', 'abilities' => ['create', 'edit.own', 'delete.own']],
        ['type' => 'film', 'role' => 'contributor', 'abilities' => ['create', 'edit.own', 'delete.own']],
    ])
        ->and(TypesFile::export()['types'][0]['permissions'])->toBe('own')
        ->and(TypesFile::export()['types'][1]['permissions'])->toBe('shared');
});

it('gives a type the file brings with its own permissions the file’s grants, and every role it doesn’t name the starting set', function (): void {
    ($this->import)(ctFile([$this->own], ['grants' => [
        ['type' => 'film', 'role' => 'author', 'abilities' => ['edit.own', 'create']],
        ['type' => 'film', 'role' => 'contributor', 'abilities' => []],
    ]]));

    expect(tpHeld('film'))->toBe([
        'administrator' => tpStarting()['administrator'],
        'editor' => tpStarting()['editor'],
        'author' => ['film.create', 'film.edit.own'],
        'contributor' => [],
    ]);
});

it('skips a grant to a role this site doesn’t have, and makes no role', function (): void {
    ($this->import)(ctFile([$this->own], ['grants' => [['type' => 'film', 'role' => 'shop_manager', 'abilities' => ['create']]]]));

    expect(Role::query()->where('name', 'shop_manager')->exists())->toBeFalse()
        ->and(tpHeld('film'))->toBe(tpStarting());
});

it('gives a type the file switches to its own permissions the file’s grants', function (): void {
    ctCreate($this->admin);

    $report = ($this->import)(ctFile([$this->own], ['grants' => [['type' => 'film', 'role' => 'editor', 'abilities' => ['edit.any']]]]));

    expect($report['updated'])->toBe(['film'])
        ->and(tpHeld('film')['editor'])->toBe(['film.edit.any'])
        ->and(tpHeld('film')['author'])->toBe(tpStarting()['author']);
});

it('keeps this site’s grants for a type that already has its own, and says so only when the file’s differ', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);

    expect(($this->import)(ctFile([$this->own], ['grants' => [['type' => 'film', 'role' => 'author', 'abilities' => ['create']]]])))
        ->toBe(['added' => [], 'updated' => [], 'unchanged' => ['film'], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => ['film'], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(tpHeld('film'))->toBe(tpStarting())
        ->and(($this->import)(ctFile([$this->own], ['grants' => [['type' => 'film', 'role' => 'author', 'abilities' => ['publish', 'create', 'delete.own', 'edit.own']]]]))['grantsKept'])->toBe([]);
});

it('takes a type the file switches back to the standard permissions off its own', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);

    expect(($this->import)(ctFile([ctDefinition()->toArray()]))['updated'])->toBe(['film'])
        ->and(Permission::query()->where('name', 'like', 'film.%')->count())->toBe(0)
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('create'))->toBe('content.create');
});

it('brings back the grants a site exported, so the type holds the same', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    Role::findByName('editor')->revokePermissionTo('film.delete.any');
    $file = TypesFile::export();

    app(DeleteType::class)->execute(new DeleteTypeInput('film', (string) $this->admin->id));
    ($this->import)($file);

    expect(tpHeld('film')['editor'])->toBe(['film.create', 'film.edit.any', 'film.edit.own', 'film.publish'])
        ->and(tpHeld('film')['author'])->toBe(tpStarting()['author']);
});

it('reads a type from a file made before types had permissions as using the standard ones', function (): void {
    $before = ctDefinition()->toArray();
    unset($before['permissions']);

    ($this->import)(ctFile([$before]));

    expect(app(ContentTypeRegistry::class)->get('film')->ability('create'))->toBe('content.create')
        ->and(Permission::query()->where('name', 'like', 'film.%')->count())->toBe(0);
});

it('refuses grants it can’t use, saying why, and writes nothing', function (array $grants, string $field, string $message): void {
    $file = ctFile([$this->own, ctDefinition(['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes'])->toArray()], ['grants' => $grants]);

    expect(fn () => ($this->import)($file))->toThrow(function (ValidationException $refusal) use ($field, $message): void {
        expect($refusal->errors()[$field] ?? [])->toContain($message);
    });

    expect(DB::table('content_type_definitions')->count())->toBe(0)
        ->and(Permission::query()->where('name', 'like', 'film.%')->count())->toBe(0);
})->with([
    'not an object' => [['film'], 'grants.0', 'Grant 1: a grant must be an object of named values.'],
    'something else in it' => [[['type' => 'film', 'role' => 'editor', 'abilities' => [], 'until' => '2027-01-01']], 'grants.0', 'Grant 1: [until] isn’t part of a version 1 grant.'],
    'no type' => [[['role' => 'editor', 'abilities' => []]], 'grants.0', 'Grant 1: [type] must be text.'],
    'no role' => [[['type' => 'film', 'abilities' => []]], 'grants.0', 'Grant 1: [role] must be text.'],
    'a permission’s name for an action' => [[['type' => 'film', 'role' => 'editor', 'abilities' => ['settings.general.manage']]], 'grants.0', 'Grant 1: [abilities] must list a type’s actions, each once: create, edit.own, edit.any, publish, delete.own, delete.any.'],
    'an action twice' => [[['type' => 'film', 'role' => 'editor', 'abilities' => ['create', 'create']]], 'grants.0', 'Grant 1: [abilities] must list a type’s actions, each once: create, edit.own, edit.any, publish, delete.own, delete.any.'],
    'a type the file hasn’t' => [[['type' => 'recipe', 'role' => 'editor', 'abilities' => ['create']]], 'grants.0', 'Grant 1: the file has no type recipe.'],
    'a type that shares the standard ones' => [[['type' => 'note', 'role' => 'editor', 'abilities' => ['create']]], 'grants.0', 'Grant 1: note uses the standard content permissions, so it has none of its own to grant.'],
    'one role twice' => [[['type' => 'film', 'role' => 'editor', 'abilities' => ['create']], ['type' => 'film', 'role' => 'editor', 'abilities' => []]], 'grants.1', 'Grant 2: the file grants film to editor twice.'],
]);
