<?php

declare(strict_types=1);

use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const CMF_RELEASE = '01K9F1E1D0000000000000RD01';

beforeEach(function (): void {
    $this->admin = ctAdmin();
});

/**
 * One teme-types.json holding all three: Movies, filed under the Genres it
 * brings, with a date-only release date of its own.
 *
 * @return array<string, mixed>
 */
function cmfFile(): array
{
    return ctFile(
        [ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'taxonomies' => ['genre'], 'supports' => ['seo', 'excerpt']])->settled()->toArray()],
        [
            'fields' => [StoredFieldRows::entry(CMF_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true]])],
            'taxonomies' => [txDefinition()->toArray()],
        ],
    );
}

/** The file as teme:types:export --output writes one. */
function cmfJson(array $file): string
{
    return json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

it('makes a type, a taxonomy on it and a field on it from one file, and exports the same file back', function (): void {
    $in = tempnam(sys_get_temp_dir(), 'types');
    $out = tempnam(sys_get_temp_dir(), 'types');
    file_put_contents($in, cmfJson(cmfFile()));

    $this->artisan('teme:types:import', ['file' => $in])
        ->expectsOutput('Added movie (Movies).')
        ->expectsOutput('Added the taxonomy genre (Genres).')
        ->expectsOutput('Added field movie.release_date (Release date).')
        ->assertSuccessful();

    expect(app(ContentTypeRegistry::class)->get('movie')->taxonomies)->toBe(['genre'])
        ->and(app(TaxonomyRegistry::class)->get('genre')->plural)->toBe('Genres')
        ->and(array_map(fn ($field): string => $field->handle, app(FieldRegistry::class)->stored('movie')))->toBe(['release_date']);

    $this->artisan('teme:types:export', ['--output' => $out])->assertSuccessful();

    expect(file_get_contents($out))->toBe(file_get_contents($in));
    unlink($in);
    unlink($out);
});

it('writes neither the taxonomy nor the type of a file whose field is refused', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(CMF_RELEASE, 'release_date', ['type' => 'post']), 1);
    $before = DB::table(StoredFields::TABLE)->get()->all();

    expect(fn () => scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput(cmfFile()))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['fields.0.type' => ['Field 1: A field can’t move to another type once it is made: release_date belongs to posts.']]))
        ->and(DB::table(StoredTypes::TABLE)->count())->toBe(0)
        ->and(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0)
        ->and(DB::table(StoredFields::TABLE)->get()->all())->toEqual($before)
        ->and(app(ContentTypeRegistry::class)->has('movie'))->toBeFalse()
        ->and(app(TaxonomyRegistry::class)->has('genre'))->toBeFalse();
});

it('shows a type’s fields summary and its taxonomies on its page', function (): void {
    scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput(cmfFile())));

    $this->actingAs($this->admin)->get('/admin/types/movie')
        ->assertInertia(fn (Assert $page) => $page->component('types/show')
            ->where('type.taxonomies', ['genre'])
            ->where('options.taxonomies', [['key' => 'category', 'plural' => 'Categories'], ['key' => 'tag', 'plural' => 'Tags'], ['key' => 'genre', 'plural' => 'Genres']])
            ->where('fields.own', ['Release date'])
            ->where('fields.fromTeme', ['Excerpt', 'Search and social'])
            ->where('fields.url', '/admin/types/movie/fields'));
});
