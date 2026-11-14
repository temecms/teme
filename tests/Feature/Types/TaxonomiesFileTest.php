<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->import = fn (array $file, ?User $actor = null): array => $actor === null
        ? scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput($file)))
        : app(ImportTypes::class)->execute(new ImportTypesInput($file, (string) $actor->id));
    $this->genres = txDefinition(['code_types' => ['post']])->toArray();
    $this->film = ctDefinition(['taxonomies' => ['genre'], 'pattern' => '/films/{slug}/'])->toArray();
});

it('exports the site’s own taxonomies beside its types, never code’s', function (): void {
    ctCreate($this->admin);
    txCreate($this->admin, [], ['film', 'post']);

    expect(TypesFile::export()['taxonomies'])->toBe([$this->genres])
        ->and(TypesFile::export()['types'][0]['taxonomies'])->toBe(['category', 'genre']);
});

it('brings a taxonomy in before the type that names it, live at once', function (): void {
    expect(($this->import)(ctFile([$this->film], ['taxonomies' => [$this->genres]]))['taxonomies'])
        ->toBe(['added' => ['genre'], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['genre'])
        ->and(app(ContentTypeRegistry::class)->get('post')->taxonomies)->toBe(['category', 'tag', 'genre']);
});

it('changes nothing when the file matches, and keeps this site’s base, saying so', function (): void {
    ($this->import)(ctFile([$this->film], ['taxonomies' => [$this->genres]]));

    expect(($this->import)(ctFile([$this->film], ['taxonomies' => [$this->genres]]))['taxonomies']['unchanged'])->toBe(['genre'])
        ->and(($this->import)(ctFile([$this->film], ['taxonomies' => [[...$this->genres, 'plural' => 'Kinds', 'base' => 'kind']]]))['taxonomies'])
        ->toBe(['added' => [], 'updated' => ['genre'], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => ['genre']])
        ->and(app(TaxonomyRegistry::class)->get('genre')->base)->toBe('genre');
});

it('leaves a taxonomy only this site has where it is, and names it', function (): void {
    txCreate($this->admin, ['key' => 'studio', 'singular' => 'Studio', 'plural' => 'Studios', 'segment' => 'studios', 'base' => 'studio', 'hierarchical' => false]);

    expect(($this->import)(ctFile([]))['taxonomies']['onlyHere'])->toBe(['studio']);
});

it('holds a file’s change to the rules a change on the site meets, writing nothing', function (): void {
    ($this->import)(ctFile([$this->film], ['taxonomies' => [$this->genres]]));
    typesTerm($this->admin, 'genre', 'Superhero', typesTerm($this->admin, 'genre', 'Action')->id);
    $before = DB::table(StoredTaxonomies::TABLE)->value('definition');

    expect(fn () => ($this->import)(ctFile([$this->film], ['taxonomies' => [[...$this->genres, 'hierarchical' => false]]])))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['taxonomies.0.hierarchical'])->toContain('1 genre sits under others, so Genres can’t become flat. Move it to the top first.');
        });

    expect(DB::table(StoredTaxonomies::TABLE)->value('definition'))->toBe($before);
});

it('refuses a file whose taxonomy names a type made on this site among code’s types, and writes nothing', function (): void {
    ctCreate($this->admin);
    $before = DB::table('content_type_definitions')->value('definition');

    expect(fn () => ($this->import)(ctFile([$this->film], ['taxonomies' => [txDefinition(['code_types' => ['film']])->toArray()]])))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['taxonomies.0.code_types'] ?? [])->toContain('Genres can be given here only to types code declares, and film isn’t one. A type made on this site names its taxonomies itself.');
        });

    expect(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->value('definition'))->toBe($before)
        ->and(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();
});

it('refuses a file whose taxonomies it can’t read, saying why, and writes nothing', function (Closure $file, string $field, string $message): void {
    expect(fn () => ($this->import)($file()))->toThrow(function (ValidationException $refusal) use ($field, $message): void {
        expect($refusal->errors()[$field] ?? [])->toContain($message);
    });

    expect(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
})->with([
    'a key twice' => [fn () => ctFile([], ['taxonomies' => [txDefinition()->toArray(), txDefinition(['segment' => 'kinds'])->toArray()]]), 'taxonomies.1.key', 'The file has the taxonomy key genre twice.'],
    'a segment twice' => [fn () => ctFile([], ['taxonomies' => [txDefinition()->toArray(), txDefinition(['key' => 'kind', 'base' => 'kind'])->toArray()]]), 'taxonomies.1.segment', 'The file gives /admin/genres to two taxonomies.'],
    'a type’s segment' => [fn () => ctFile([ctDefinition(['segment' => 'genres'])->toArray()], ['taxonomies' => [txDefinition()->toArray()]]), 'taxonomies.0.segment', 'The file gives /admin/genres to a type and a taxonomy.'],
    'a key code declares' => [fn () => ctFile([], ['taxonomies' => [txDefinition(['key' => 'tag', 'segment' => 'labels', 'base' => 'label'])->toArray()]]), 'taxonomies.0.key', 'tag is declared by code on this site, so a file can’t bring it.'],
    'a new base a new type’s pattern takes' => [fn () => ctFile([ctDefinition(['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'reels', 'pattern' => '/genre/{slug}/', 'taxonomies' => []])->toArray()], ['taxonomies' => [txDefinition()->toArray()]]), 'taxonomies.0.base', 'Addresses starting /genre/ are already in use on this site.'],
    'a type naming one nobody has' => [fn () => ctFile([ctDefinition(['taxonomies' => ['studio']])->toArray()]), 'types.0.taxonomies', 'There is no taxonomy studio.'],
]);

it('writes nothing when authorization fails to import taxonomies', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->import)(ctFile([], ['taxonomies' => [$this->genres]]), $editor))->toThrow(AuthorizationException::class)
        ->and(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0);
});
