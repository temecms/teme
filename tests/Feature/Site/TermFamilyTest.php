<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Site\ListingViews;
use App\Domain\Site\ViewModels\TermLink;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Types\ContentTypeRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();

    // World › Africa › Ghana, Nigeria and Togo, which holds nothing; each
    // other one holds a post. Science is a tag.
    $this->world = tfTerm('category', 'World');
    $this->africa = tfTerm('category', 'Africa', $this->world);
    $this->nigeria = tfTerm('category', 'Nigeria', $this->africa);
    $this->ghana = tfTerm('category', 'Ghana', $this->africa);
    $this->togo = tfTerm('category', 'Togo', $this->africa);
    $this->science = tfTerm('tag', 'Science');

    pageArticle($this->admin, 'Across the continent', categories: [$this->africa]);
    pageArticle($this->admin, 'Lagos traffic', categories: [$this->nigeria], tags: [$this->science]);
    pageArticle($this->admin, 'Accra markets', categories: [$this->ghana]);
    $this->folder = null;
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

/**
 * A term made through CreateTerm, so it has its address.
 */
function tfTerm(string $taxonomy, string $name, ?Term $parent = null): Term
{
    return app(CreateTerm::class)->execute(new SaveTermInput($taxonomy, $name, null, $parent?->id, null, (string) test()->admin->id));
}

/**
 * The names of these links, in order.
 *
 * @param  list<TermLink>  $links
 *
 * @return list<string>
 */
function tfNames(array $links): array
{
    return array_map(fn (TermLink $link): string => $link->name, $links);
}

it('gives a term’s page its taxonomy, its parent and its children with published items, by name', function (): void {
    $subject = app(ListingViews::class)->forTerm($this->africa->refresh(), 1)->subject;

    expect($subject->taxonomy)->toBe('category')
        ->and($subject->parent?->name)->toBe('World')
        ->and($subject->parent?->url)->toBe('/category/world/')
        ->and(tfNames($subject->children))->toBe(['Ghana', 'Nigeria'])
        ->and($subject->children[0]->url)->toBe('/category/world/africa/ghana/');
});

it('gives a top term no parent, and a flat taxonomy’s term neither', function (): void {
    $world = app(ListingViews::class)->forTerm($this->world->refresh(), 1)->subject;
    $science = app(ListingViews::class)->forTerm($this->science->refresh(), 1)->subject;

    expect($world->parent)->toBeNull()
        ->and(tfNames($world->children))->toBe(['Africa'])
        ->and([$science->taxonomy, $science->parent, $science->children])->toBe(['tag', null, []]);
});

it('asks two statements more for a hierarchical term, and none for a flat one', function (): void {
    $family = fn (array $statements): array => array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, '"terms"."parent_id" = ?') || str_contains($sql, '"terms"."id" = ?')));

    app(ListingViews::class)->forTerm($this->africa->refresh(), 1);
    app(ListingViews::class)->forTerm($this->science->refresh(), 1);
    app()->forgetScopedInstances();

    expect($family(dashQueries(fn () => app(ListingViews::class)->forTerm($this->africa->refresh(), 1))))->toHaveCount(2)
        ->and($family(dashQueries(fn () => app(ListingViews::class)->forTerm($this->science->refresh(), 1))))->toBe([]);
});

it('records each child’s and the parent’s page tag', function (): void {
    app(RenderRecord::class)->start(0);

    app(ListingViews::class)->forTerm($this->africa->refresh(), 1);

    expect(app(RenderRecord::class)->tags())->toContain(PageTags::term($this->world->id), PageTags::term($this->ghana->id), PageTags::term($this->nigeria->id))
        ->not->toContain(PageTags::term($this->togo->id));
});

it('records what is filed under each child, one with no published item too, so the page changes when a child gains or loses one', function (): void {
    app(RenderRecord::class)->start(0);

    app(ListingViews::class)->forTerm($this->africa->refresh(), 1);

    expect(app(RenderRecord::class)->tags())->toContain(
        PageTags::filedUnder('category', $this->ghana->id),
        PageTags::filedUnder('category', $this->nigeria->id),
        PageTags::filedUnder('category', $this->togo->id),
    );
});

it('lists a child on its parent’s cached page once its first item is published, and leaves it out again once that item is unpublished', function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();
    $children = fn (): array => tfNames(addrGet('/category/world/africa/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->viewData('page')->subject->children);

    expect($children())->toBe(['Ghana', 'Nigeria'])
        ->and(cacheEntry('/category/world/africa/'))->not->toBeNull();

    $lome = pageArticle($this->admin, 'Lomé at dawn', categories: [$this->togo]);

    expect(cacheEntry('/category/world/africa/'))->toBeNull()
        ->and($children())->toBe(['Ghana', 'Nigeria', 'Togo']);

    app(UnpublishContent::class)->execute(new ContentActionInput($lome->id, (string) $this->admin->id));

    expect(cacheEntry('/category/world/africa/'))->toBeNull()
        ->and($children())->toBe(['Ghana', 'Nigeria']);
});

it('works for a site’s own hierarchical taxonomy', function (): void {
    txStoreRow(txDefinition());
    ctStoreRow(ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'taxonomies' => ['genre'], 'supports' => []]));
    app(ContentTypeRegistry::class)->refreshStored();
    $drama = tfTerm('genre', 'Drama');
    $period = tfTerm('genre', 'Period drama', $drama);
    $movie = lifecycleDraft($this->admin, 'The Remains', 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, 'The Remains', ['type' => 'doc', 'content' => []], [], $movie->working_revision_id, (string) $this->admin->id, [$period->id]));
    listsPublish($movie, $this->admin);

    $subject = app(ListingViews::class)->forTerm($drama->refresh(), 1)->subject;

    expect($subject->taxonomy)->toBe('genre')
        ->and(tfNames($subject->children))->toBe(['Period drama']);
});
