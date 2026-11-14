<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    Switches::apiOn();
});

/**
 * The refused parameters' words, by name.
 *
 * @return array<string, string>
 */
function parentsErrors(TestResponse $response): array
{
    $response->assertStatus(400);

    return collect($response->json('errors'))->mapWithKeys(fn (array $error): array => [$error['parameter'] => $error['detail']])->all();
}

/**
 * $count published children under the page, each a second after the last,
 * at positions 1 to $count in reverse of their publishing, so the two
 * orders differ. Answers their IDs in position order.
 *
 * @return list<string>
 */
function parentsChildren(Content $parent, int $count): array
{
    $ids = [];

    for ($n = 1; $n <= $count; $n++) {
        test()->travel(1)->seconds();
        $child = addrPublished(test()->tree->editor, "Child {$n}", 'page');
        PageTree::place($child, $parent, $count - $n + 1);
        $ids[$count - $n + 1] = $child->id;
    }

    ksort($ids);

    return array_values($ids);
}

it('gives a page\'s parent_id while its parent is live, and null otherwise', function (): void {
    $parentOf = fn (Content $page): mixed => $this->getJson("/api/v1/items/{$page->id}")->assertOk()->json('data.parent_id');

    expect($parentOf($this->tree->team))->toBe($this->tree->about->id)
        ->and($parentOf($this->tree->secretChild))->toBeNull()
        ->and($parentOf($this->tree->about))->toBeNull()
        ->and(collect($this->getJson('/api/v1/items?type=page&limit=100')->assertOk()->json('data'))->keyBy('id')[$this->tree->people->id]['parent_id'])->toBe($this->tree->team->id);
});

it('gives a full item its live ancestors, the top first, by their published titles', function (): void {
    $ancestors = $this->getJson("/api/v1/items/{$this->tree->people->id}")->assertOk()->json('data.ancestors');

    expect(array_map(fn (array $link): array => [$link['id'], $link['type'], $link['title'], $link['path'], $link['url']], $ancestors))->toBe([
        [$this->tree->about->id, 'page', 'About', '/about/', 'http://localhost/about/'],
        [$this->tree->team->id, 'page', 'Team', '/about/team/', 'http://localhost/about/team/'],
    ])
        ->and(array_keys($ancestors[0]))->toBe(['id', 'type', 'language', 'title', 'path', 'url'])
        ->and(array_column($this->getJson("/api/v1/items/{$this->tree->secretChild->id}")->json('data.ancestors'), 'title'))->toBe(['About'])
        ->and($this->getJson("/api/v1/items/{$this->tree->about->id}")->json('data.ancestors'))->toBe([])
        ->and($this->getJson("/api/v1/items/{$this->tree->teamPost->id}")->json('data.ancestors'))->toBe([]);
});

it('lists a page\'s live children with ?parent=, newest first by default', function (): void {
    expect(array_column($this->getJson("/api/v1/items?parent={$this->tree->about->id}")->assertOk()->json('data'), 'id'))
        ->toEqualCanonicalizing([$this->tree->team->id, $this->tree->history->id]);

    $hub = addrPublished($this->tree->editor, 'Hub', 'page');
    $ids = parentsChildren($hub, 3);

    expect(array_column($this->getJson("/api/v1/items?parent={$hub->id}")->assertOk()->json('data'), 'id'))->toBe($ids);
});

it('orders them by position with sort=position, following links.next to the end', function (): void {
    $hub = addrPublished($this->tree->editor, 'Hub', 'page');
    $ids = parentsChildren($hub, 25);
    $walked = [];
    $next = "/api/v1/items?parent={$hub->id}&sort=position&limit=10";

    while ($next !== null) {
        $page = $this->getJson($next)->assertOk()->json();
        $walked = [...$walked, ...array_column($page['data'], 'id')];
        $next = $page['links']['next'];
    }

    expect($walked)->toBe($ids);
});

it('answers an empty list for a hidden parent\'s ID, exactly as for an unknown one', function (): void {
    $hidden = $this->getJson("/api/v1/items?parent={$this->tree->secret->id}")->assertOk()->json();
    $unknown = $this->getJson('/api/v1/items?parent=01jcb7y1r9n3w4m5p6q7s8t9v0')->assertOk()->json();
    unset($hidden['links']['self'], $unknown['links']['self']);

    expect($hidden['data'])->toBe([])
        ->and($hidden)->toBe($unknown);
});

it('refuses a parent that isn\'t an ID, and sort=position without parent, in these words', function (): void {
    expect(parentsErrors($this->getJson('/api/v1/items?parent=about')))->toBe(['parent' => 'parent must be the ID of an item.'])
        ->and(parentsErrors($this->getJson('/api/v1/items?sort=position')))->toBe(['sort' => 'sort must be published_at, -published_at, or position with parent.']);
});

it('tags a ?parent= list with the parent\'s children list', function (): void {
    $folder = cacheOn();
    $address = "/api/v1/items?parent={$this->tree->about->id}";
    $this->getJson($address)->assertOk();

    expect(cacheEntry($address)?->tags)->toContain(PageTags::childrenOf($this->tree->about->id), PageTags::listOf('page'));
    cacheOff($folder);
});

it('records a listed item\'s parent, so a stored list answers parent_id null at once once it is hidden', function (): void {
    $folder = cacheOn();
    // The page of the list, one item a page, that holds Team and not About.
    $address = '/api/v1/items?type=page&limit=1';

    while (($page = $this->getJson($address)->assertOk()->json())['data'][0]['id'] !== $this->tree->team->id) {
        $address = (string) parse_url((string) $page['links']['next'], PHP_URL_PATH).'?'.parse_url((string) $page['links']['next'], PHP_URL_QUERY);
    }

    expect($page['data'][0]['parent_id'])->toBe($this->tree->about->id)
        ->and(cacheEntry($address)?->tags)->toContain(PageTags::content($this->tree->about->id));

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($this->tree->about->id, 'private', null, (string) $this->tree->editor->id));

    expect($this->getJson($address)->assertOk()->json('data.0.parent_id'))->toBeNull();
    cacheOff($folder);
});
