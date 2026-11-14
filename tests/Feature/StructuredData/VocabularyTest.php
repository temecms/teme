<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\StructuredData\Vocabulary;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    sdTrailTheme();
});

it('writes only terms on the committed list, on every kind of page core draws (I-706)', function (string $page): void {
    $author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $author->forceFill(['bio' => 'Covers the harbour board.'])->save();
    app(PathProjection::class)->syncUser($author);
    $editor = User::factory()->create()->assignRole('editor');
    $picture = pageImage($author, 1600, 900);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($picture->id, (string) $author->id, $picture->title, null, 'Boats at dawn', 'Harbour Trust'));
    $local = sdCategory('Local', sdCategory('News'));
    headPost($author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $local->id], [$local, headTerm('tag', 'Tides')], featured: $picture);
    $about = lifecycleDraft($editor, 'About us', 'page');
    docSave($about, $editor, ['type' => 'doc', 'content' => []], [BuiltInFields::PAGE_TYPE => 'AboutPage', BuiltInFields::ARTICLE_TYPE => 'NewsArticle']);
    listsPublish($about, $editor);
    [$uri, $status] = match ($page) {
        'an article' => ['/harbour-news/', 200],
        'an About page that is a news article' => ['/about-us/', 200],
        'a category' => ['/category/news/local/', 200],
        'an author' => ['/author/jane-doe/', 200],
        'a search' => ['/?s=harbour', 200],
        'the home page' => ['/', 200],
        default => ['/no-such-page/', 404],
    };

    $graph = sdGraph($uri, $status);

    expect($graph)->not->toBe([])
        ->and(app(Vocabulary::class)->unknown($graph))->toBe([]);
})->with(['an article', 'an About page that is a news article', 'a category', 'an author', 'a search', 'the home page', 'a page not found']);

it('names each term a graph uses that isn\'t on the list, at any depth, until a pack registers it (I-706)', function (): void {
    $graph = [['@id' => 'https://example.test/soup/#recipe', '@type' => 'Recipe', 'name' => 'Leek soup', 'recipeIngredient' => ['Leeks'], 'nutrition' => ['@type' => 'NutritionInformation', 'calories' => '200 calories']]];
    $vocabulary = app(Vocabulary::class);
    $before = $vocabulary->unknown($graph);

    $vocabulary->register(['Recipe', 'NutritionInformation'], ['recipeIngredient', 'nutrition', 'calories']);

    expect($before)->toBe(['Recipe', 'recipeIngredient', 'nutrition', 'NutritionInformation', 'calories'])
        ->and($vocabulary->unknown($graph))->toBe([])
        ->and($vocabulary->has('Recipe'))->toBeTrue();
});

it('checks the list against a schema.org release, naming each term the release lacks or supersedes (I-706)', function (): void {
    $terms = [...app(Vocabulary::class)->types(), ...app(Vocabulary::class)->properties()];
    $release = function (array $terms, array $superseded = []): string {
        $path = storage_path('framework/testing/schemaorg-release.jsonld');
        file_put_contents($path, (string) json_encode(['@context' => ['schema' => 'https://schema.org/'], '@graph' => array_map(
            fn (string $term): array => ['@id' => 'schema:'.$term, ...(isset($superseded[$term]) ? ['schema:supersededBy' => ['@id' => 'schema:'.$superseded[$term]]] : [])],
            $terms,
        )]));

        return $path;
    };

    $this->artisan('teme:structured-data:check', ['release' => $release($terms)])
        ->expectsOutput('Every term Teme writes is in this release.')
        ->assertSuccessful();
    $this->artisan('teme:structured-data:check', ['release' => $release(array_values(array_diff($terms, ['creditText'])))])
        ->expectsOutput('Not in this release: creditText')
        ->assertFailed();
    $this->artisan('teme:structured-data:check', ['release' => $release($terms, ['headline' => 'name'])])
        ->expectsOutput('Superseded in this release: headline, by name')
        ->assertFailed();
});

it('refuses a file that isn\'t there, or isn\'t a schema.org release (I-706)', function (): void {
    $path = storage_path('framework/testing/not-a-release.json');
    file_put_contents($path, '{"name": "x"}');

    $this->artisan('teme:structured-data:check', ['release' => $path])
        ->expectsOutput("That isn't a schema.org release file: it has no @graph.")
        ->assertFailed();
    $this->artisan('teme:structured-data:check', ['release' => storage_path('framework/testing/no-such-file.jsonld')])
        ->expectsOutput('There is no file at '.storage_path('framework/testing/no-such-file.jsonld').'.')
        ->assertFailed();
});
