<?php

declare(strict_types=1);

use App\Domain\Addresses\Models\Path;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\ImportTerm;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->importer = User::factory()->create();
    $this->importer->assignRole('administrator');
});

/**
 * Imports one term as $actor (the operator when null), with these fields
 * over a new top-level category's.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array{termId: string, created: bool}
 */
function itsImport(array $fields, ?User $actor): array
{
    return app(ImportTerm::class)->execute(new ImportTermInput(...[
        'taxonomy' => 'category',
        'name' => 'Animals',
        'slug' => 'animals',
        'description' => null,
        'parentId' => null,
        'termId' => null,
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$fields,
    ]));
}

function itsPath(string $termId): mixed
{
    return Path::query()->where('target_type', 'term')->where('target_id', $termId)->where('is_current', true)->value('path');
}

/**
 * @return list<string>
 */
function itsHistory(string $termId): array
{
    return Path::query()->where('target_type', 'term')->where('target_id', $termId)->where('is_current', false)->orderBy('path')->pluck('path')->all();
}

it('creates a category under its parent, at its address', function (): void {
    $animals = itsImport([], $this->importer);
    $owls = itsImport(['name' => 'Owls', 'slug' => 'owls', 'description' => 'Night birds.', 'parentId' => $animals['termId']], $this->importer);
    $term = Term::query()->findOrFail($owls['termId']);

    expect($owls['created'])->toBeTrue()
        ->and([$term->taxonomy, $term->name, $term->slug, $term->parent_id, $term->description])->toBe(['category', 'Owls', 'owls', $animals['termId'], 'Night birds.'])
        ->and(itsPath($owls['termId']))->toBe('category/animals/owls');
});

it('creates a tag at an address in any script', function (): void {
    $cafe = itsImport(['taxonomy' => 'tag', 'name' => 'Café', 'slug' => 'café'], $this->importer);

    expect(Term::query()->findOrFail($cafe['termId'])->slug)->toBe('café')
        ->and(itsPath($cafe['termId']))->toBe('tag/café');
});

it('updates a term this import made, keeping its old address as a redirect', function (): void {
    $animals = itsImport([], $this->importer);

    $again = itsImport(['name' => 'Wildlife', 'slug' => 'wildlife', 'description' => 'Everything that lives.', 'termId' => $animals['termId']], $this->importer);
    $term = Term::query()->findOrFail($animals['termId']);

    expect($again)->toBe(['termId' => $animals['termId'], 'created' => false])
        ->and([$term->name, $term->slug, $term->description])->toBe(['Wildlife', 'wildlife', 'Everything that lives.'])
        ->and(itsPath($animals['termId']))->toBe('category/wildlife')
        ->and(itsHistory($animals['termId']))->toBe(['category/animals']);
});

it('moves a category under its parent, and its address with it', function (): void {
    $owls = itsImport(['name' => 'Owls', 'slug' => 'owls'], $this->importer);
    $animals = itsImport([], $this->importer);

    itsImport(['name' => 'Owls', 'slug' => 'owls', 'parentId' => $animals['termId'], 'termId' => $owls['termId']], $this->importer);

    expect(Term::query()->findOrFail($owls['termId'])->parent_id)->toBe($animals['termId'])
        ->and(itsPath($owls['termId']))->toBe('category/animals/owls')
        ->and(itsHistory($owls['termId']))->toBe(['category/owls']);
});

it('refuses what Teme can not keep, writing nothing', function (string $case, string $key): void {
    $news = taxonomyTerm('category', 'News');
    $local = taxonomyTerm('category', 'Local', $news->id);
    $owls = taxonomyTerm('tag', 'Owls');
    $before = Term::query()->count();

    $fields = match ($case) {
        'slug taken' => ['name' => 'News Desk', 'slug' => 'news'],
        'tag as parent' => ['parentId' => $owls->id],
        'inside itself' => ['name' => 'News', 'slug' => 'news', 'termId' => $news->id, 'parentId' => $local->id],
        'tag with parent' => ['taxonomy' => 'tag', 'name' => 'Chicks', 'slug' => 'chicks', 'parentId' => $news->id],
        default => ['slug' => ''],
    };

    try {
        itsImport($fields, $this->importer);
        $this->fail('Nothing was refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey($key);
    }

    expect(Term::query()->count())->toBe($before)
        ->and(Term::query()->findOrFail($news->id)->parent_id)->toBeNull();
})->with([
    'an address another category has' => ['slug taken', 'slug'],
    'a tag as a category\'s parent' => ['tag as parent', 'parent'],
    'a category inside its own child' => ['inside itself', 'parent'],
    'a parent for a tag, which can\'t have one' => ['tag with parent', 'parent'],
    'no address' => ['no address', 'slug'],
]);

it('lets the operator import a term', function (): void {
    $result = scElevated(fn (): array => itsImport([], null));

    expect(Term::query()->findOrFail($result['termId'])->name)->toBe('Animals');
});

it('writes nothing when the actor may not import', function (string $who): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    expect(fn () => itsImport([], $who === 'editor' ? $editor : null))->toThrow(AuthorizationException::class)
        ->and(Term::query()->count())->toBe(0)
        ->and(Path::query()->where('target_type', 'term')->count())->toBe(0);
})->with([
    'an editor, who manages terms but may not import' => ['editor'],
    'no one, away from the operator\'s shell' => ['no one'],
]);
