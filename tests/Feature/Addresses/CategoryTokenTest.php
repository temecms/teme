<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    // A second hierarchical taxonomy, as a site will make its own (I-414):
    // Films take {category} from their first hierarchical one, Genres.
    app(TaxonomyRegistry::class)->register(new Taxonomy('genre', 'Genre', 'Genres', 'genres', hierarchical: true, base: 'genre'));
    ctCreate(null, ['taxonomies' => ['tag', 'genre', 'category'], 'pattern' => '/films/{category}/{slug}/']);
    // Recipes name {category} but file under tags alone: nothing to take it from.
    ctCreate(null, ['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'segment' => 'recipes', 'icon' => 'cooking-pot', 'archive' => false, 'taxonomies' => ['tag'], 'supports' => ['seo', 'excerpt'], 'comments' => 'open', 'pattern' => '/recipes/{category}/{slug}/']);
    $this->drama = typesTerm($this->admin, 'genre', 'Drama');
    $this->news = typesTerm($this->admin, 'category', 'News');
    $this->quick = typesTerm($this->admin, 'tag', 'Quick');
});

it('takes a film’s {category} from its type’s first hierarchical taxonomy, its genres (I-869)', function (): void {
    $film = ltItem($this->admin, 'film', 'Arrival', [$this->quick, $this->news, $this->drama]);

    expect(PrimaryCategory::taxonomyFor('film'))->toBe('genre')
        ->and(PrimaryCategory::taxonomyFor('post'))->toBe('category')
        ->and(PrimaryCategory::taxonomyFor('recipe'))->toBeNull()
        ->and(PrimaryCategory::taxonomyFor('page'))->toBeNull()
        ->and(addrCurrent('content', $film->id))->toBe('films/drama/arrival');
});

it('says which taxonomies’ terms move addresses, and which types’ addresses follow a category', function (): void {
    $addresses = AddressSettings::current();

    expect($addresses->termsMoveItems('genre'))->toBeTrue()
        ->and($addresses->termsMoveItems('category'))->toBeFalse()
        ->and($addresses->termsMoveItems('tag'))->toBeFalse()
        ->and($addresses->followsCategory('film'))->toBeTrue()
        ->and($addresses->followsCategory('recipe'))->toBeFalse()
        ->and($addresses->followsCategory('post'))->toBeFalse();
});

it('moves films when a genre is renamed, and queues nothing for a tag or a category no address takes', function (): void {
    Queue::fake();
    $rename = fn (object $term, string $name) => app(UpdateTerm::class)->execute(new SaveTermInput((string) $term->taxonomy, $name, null, null, null, (string) $this->admin->id, termId: (string) $term->id));

    $rename($this->quick, 'Speedy');
    $rename($this->news, 'Press');

    Queue::assertNotPushed(RecomputeAddresses::class);

    $rename($this->drama, 'Melodrama');

    Queue::assertPushed(RecomputeAddresses::class, fn (RecomputeAddresses $job): bool => $job->termId === (string) $this->drama->id);
});

// A guard: posts keep moving with their category, as before.
it('moves posts when a category is renamed once their address takes {category}', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{category}/{slug}/']]);
    Queue::fake();

    app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Press', null, null, null, (string) $this->admin->id, termId: (string) $this->news->id));

    Queue::assertPushed(RecomputeAddresses::class);
});

it('queues no recompute when a tag is deleted, and one when a genre is', function (): void {
    Queue::fake();

    app(DeleteTerm::class)->execute(new DeleteTermInput((string) $this->quick->id, (string) $this->admin->id));

    Queue::assertNotPushed(RecomputeAddresses::class);

    app(DeleteTerm::class)->execute(new DeleteTermInput((string) $this->drama->id, (string) $this->admin->id));

    Queue::assertPushed(RecomputeAddresses::class);
});

it('tells the editor a film’s address follows its genre, and a recipe’s doesn’t, though its pattern names {category} (I-869)', function (): void {
    $film = lifecycleDraft($this->admin, 'Arrival', 'film');
    $recipe = lifecycleDraft($this->admin, 'Fish stew', 'recipe');

    $this->actingAs($this->admin)->get("/admin/content/{$film->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('seo.addressFollowsCategory', true));

    $this->actingAs($this->admin)->get("/admin/content/{$recipe->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('seo.addressFollowsCategory', false));
});

it('fills Settings › Addresses’ example {category} with the newest post’s category, never its first tag', function (): void {
    ltItem($this->admin, 'post', 'Harbour story', [$this->quick, $this->news]);

    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->where('example.category', 'news'));
});

it('fills the example {category} with the post’s chosen primary category, as its address has it (I-869)', function (): void {
    $sport = typesTerm($this->admin, 'category', 'Sport');
    headPost($this->admin, 'Harbour story', [BuiltInFields::PRIMARY_CATEGORY => $sport->id], [$this->news, $sport]);

    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->where('example.category', 'sport'));
});
