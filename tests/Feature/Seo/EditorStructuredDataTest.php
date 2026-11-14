<?php

declare(strict_types=1);

use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\Precedence;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->ask = fn (User $viewer, string $content, ?string $revision) => $this->actingAs($viewer)
        ->getJson(route('content.structured-data', ['content' => $content, ...($revision === null ? [] : ['revision' => $revision])]));
});

it('tells whoever may edit an item what the gate leaves out of its page, for the revision asked about (I-703)', function (): void {
    headWith(Precedence::Plugin, [new JsonLdNode(['@id' => 'https://example.test/soup#recipe', '@type' => 'Recipe', 'name' => 'Fish soup'])]);
    $post = addrPublished($this->author, 'Harbour news');

    ($this->ask)($this->author, $post->id, $post->working_revision_id)
        ->assertOk()
        ->assertExactJson(['withheld' => [['id' => 'https://example.test/soup#recipe', 'types' => ['Recipe'], 'missing' => [['image']]]]]);
});

it('answers with nothing left out when the gate leaves nothing out', function (): void {
    $post = addrPublished($this->author, 'Harbour news');

    ($this->ask)($this->author, $post->id, $post->working_revision_id)->assertOk()->assertExactJson(['withheld' => []]);
});

it('checks a draft at the address it would take, so its own page\'s nodes are checked before it is published (I-703)', function (): void {
    // A plugin's filter describes a recipe on the item's own page, which only a page with an address has.
    sdFilterWith(Precedence::Plugin, fn (array $graph): array => [
        ...$graph,
        ...array_map(
            fn (array $node): array => ['@id' => $node['@id'].'#recipe', '@type' => 'Recipe', 'name' => 'Fish soup'],
            array_values(array_filter($graph, fn (array $node): bool => ($node['@type'] ?? null) === 'WebPage')),
        ),
    ]);
    $draft = lifecycleDraft($this->author, 'Harbour news');

    ($this->ask)($this->author, $draft->id, $draft->working_revision_id)
        ->assertOk()
        ->assertExactJson(['withheld' => [['id' => 'http://localhost/harbour-news/#recipe', 'types' => ['Recipe'], 'missing' => [['image']]]]]);
});

it('refuses someone who may not edit the item, and tells them nothing of its graph', function (): void {
    $post = addrPublished($this->author, 'Harbour news');
    $other = User::factory()->create()->assignRole('author');

    ($this->ask)($other, $post->id, $post->working_revision_id)->assertForbidden()->assertJsonMissingPath('withheld');
});

it('answers not found for a revision of another item, or none named', function (): void {
    $post = addrPublished($this->author, 'Harbour news');
    $other = addrPublished($this->author, 'Ferry fares');

    ($this->ask)($this->author, $post->id, $other->working_revision_id)->assertNotFound();
    ($this->ask)($this->author, $post->id, null)->assertNotFound();
});

it('says in plain words what each node left out lacks (I-703)', function (array $node, string $words): void {
    expect(guardNode('core-checks.mjs', ['withheld' => [$node]]))->toBe([$words]);
})->with([
    'one property' => [['id' => 'a', 'types' => ['Recipe'], 'missing' => [['image']]], 'Recipe markup left out: no image.'],
    'one of several' => [['id' => 'a', 'types' => ['Product'], 'missing' => [['offers', 'review', 'aggregateRating']]], 'Product markup left out: no offer, review or rating.'],
    'two properties' => [['id' => 'a', 'types' => ['VideoObject'], 'missing' => [['thumbnailUrl'], ['uploadDate']]], 'Video markup left out: no thumbnail and no upload date.'],
    'a path, among types' => [['id' => 'a', 'types' => ['CollectionPage', 'ProfilePage'], 'missing' => [['mainEntity.name']]], 'Profile page markup left out: no name of who it’s about.'],
    'a type and property a pack adds' => [['id' => 'a', 'types' => ['Course'], 'missing' => [['provider']]], 'Course markup left out: no provider.'],
    'a name the object has already' => [['id' => 'a', 'types' => ['constructor'], 'missing' => [['toString']]], 'constructor markup left out: no toString.'],
]);

it('puts the structured-data check last in the list once the endpoint has answered, and says when it couldn\'t (I-703, I-880)', function (): void {
    $base = ['document' => ['hasTitle' => true, 'missingAlt' => 0, 'hasExcerpt' => true, 'hasFeaturedImage' => true, 'badLinks' => []], 'imageCards' => 0, 'featuredMissingAlt' => false, 'hasExcerptField' => true, 'hasFeaturedImageField' => true, 'description' => ['text' => 'x', 'from' => 'meta'], 'titleWidth' => 300, 'internalLinks' => 1, 'listing' => 'listed', 'published' => false, 'publishing' => false, 'noun' => 'post', 'plural' => 'posts'];
    $last = fn (mixed $withheld): array => array_slice(guardNode('core-checks.mjs', ['checks' => [...$base, 'withheld' => $withheld]]), -1)[0];

    expect($last(null)['id'])->toBe('listing')
        ->and([$last([])['id'], $last([])['state'], $last([])['message'], $last([])['search']])->toBe(['structured-data', 'ok', 'Nothing left out.', true])
        ->and([$last('unknown')['state'], $last('unknown')['message']])->toBe(['warn', 'Couldn’t be checked just now.'])
        ->and($last([['id' => 'a', 'types' => ['Recipe'], 'missing' => [['image']]]])['message'])->toBe('Recipe markup left out: no image.');
});
