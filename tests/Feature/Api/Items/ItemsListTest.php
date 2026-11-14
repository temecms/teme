<?php

declare(strict_types=1);

use App\Domain\Api\Cursor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
});

/**
 * Every item's ID a walk of the list gives, following links.next from
 * this address to the end, with $between run after each page.
 *
 * @return list<string>
 */
function itemsListWalk(string $first, ?Closure $between = null): array
{
    $ids = [];
    $next = $first;

    while ($next !== null) {
        $page = test()->getJson($next)->assertOk()->json();
        $ids = [...$ids, ...array_column($page['data'], 'id')];
        $next = $page['links']['next'];

        if ($between !== null) {
            $between();
        }
    }

    return $ids;
}

/**
 * $count published posts by this person, a second apart, oldest first.
 *
 * @return list<string>
 */
function itemsListPosts(User $author, int $count, string $prefix = 'Post'): array
{
    $ids = [];

    for ($n = 1; $n <= $count; $n++) {
        test()->travel(1)->seconds();
        $ids[] = addrPublished($author, "{$prefix} {$n}")->id;
    }

    return $ids;
}

it('lists live public items of every public type, newest first, 20 by default', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $body = $this->getJson('/api/v1/items')->assertOk()->assertHeader('Content-Type', 'application/json')->json();
    $ids = array_column($body['data'], 'id');
    $dates = array_column($body['data'], 'published_at');
    $sorted = $dates;
    rsort($sorted);

    expect($ids)->toEqualCanonicalizing([$site->public->id, $site->sequel->id, $site->protected->id, $site->about->id, $site->newerDraft->id, $site->pointer->id])
        ->and(array_unique(array_column($body['data'], 'type')))->toEqualCanonicalizing(['movie', 'page'])
        ->and($dates)->toBe($sorted)
        ->and($body['meta'])->toBe(['limit' => 20])
        ->and($body['links']['next'])->toBeNull();

    itemsListPosts($site->admin, 21);
    $page = $this->getJson('/api/v1/items')->assertOk()->json();

    expect($page['data'])->toHaveCount(20)
        ->and($page['data'][0]['title'])->toBe('Post 21')
        ->and($page['links']['next'])->not->toBeNull();
});

it('gives each item D6\'s summary, every member present', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $data = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->keyBy('id');
    $members = ['id', 'type', 'title', 'slug', 'path', 'url', 'parent_id', 'visibility', 'language', 'published_at', 'updated_at', 'excerpt', 'featured_image', 'author', 'authors', 'terms', 'links'];
    $arrival = $data[$site->public->id];

    foreach ($data as $item) {
        expect(array_keys($item))->toEqualCanonicalizing($members)
            ->and($item['parent_id'])->toBeNull()
            ->and($item['language'])->toBe('en-GB')
            ->and($item['published_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($item['links'])->toBe(['self' => 'https://example.com/api/v1/items/'.$item['id']]);
    }

    expect($arrival['title'])->toBe('Arrival')
        ->and($arrival['type'])->toBe('movie')
        ->and($arrival['slug'])->toBe('arrival')
        ->and($arrival['visibility'])->toBe('public')
        ->and($arrival['path'])->toStartWith('/')->toEndWith('/')
        ->and($arrival['url'])->toBe('https://example.com'.$arrival['path'])
        ->and($arrival['excerpt'])->toBe('A linguist is asked to talk to the visitors.')
        ->and($arrival['featured_image']['id'])->toBe($site->shell->id)
        ->and($arrival['featured_image']['alt'])->toBe('Louise Banks at the shell')
        ->and(array_keys($arrival['author']))->toEqualCanonicalizing(['name', 'slug', 'path', 'url', 'bio'])
        ->and($arrival['author']['name'])->toBe('Ada Obi')
        // Keyed by the type's public taxonomies only: genre, never shelf.
        ->and(array_keys($arrival['terms']))->toBe(['genre'])
        ->and(array_column($arrival['terms']['genre'], 'id'))->toBe([$site->genre->id])
        ->and(array_keys($data[$site->sequel->id]['terms']))->toBe(['genre'])
        ->and($data[$site->sequel->id]['terms']['genre'])->toBe([])
        ->and($data[$site->sequel->id]['excerpt'])->toBeNull()
        ->and($data[$site->sequel->id]['featured_image'])->toBeNull();
});

it('follows links.next to the end, giving each item once while items are published meanwhile', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $original = itemsListPosts($admin, 25);
    $added = 0;

    $walked = itemsListWalk('/api/v1/items?limit=10', function () use ($admin, &$added): void {
        if ($added < 2) {
            $this->travel(1)->seconds();
            addrPublished($admin, 'Meanwhile '.++$added);
        }
    });

    expect($walked)->toBe(array_reverse($original))
        ->and($added)->toBe(2);
});

it('walks oldest first with sort=published_at', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $original = itemsListPosts($admin, 7);

    expect(itemsListWalk('/api/v1/items?sort=published_at&limit=3'))->toBe($original)
        ->and(itemsListWalk('/api/v1/items?sort=-published_at&limit=3'))->toBe(array_reverse($original));
});

it('writes links.self and links.next as canonical addresses with the parameters sorted', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    itemsListPosts($admin, 3);

    $body = $this->getJson('/api/v1/items?type=post&limit=2&sort=-published_at')->assertOk()->json();
    $after = [];
    parse_str((string) parse_url((string) $body['links']['next'], PHP_URL_QUERY), $after);

    expect($body['links']['self'])->toBe('https://example.com/api/v1/items?limit=2&sort=-published_at&type=post')
        ->and($body['links']['next'])->toStartWith('https://example.com/api/v1/items?after=')
        ->and($body['links']['next'])->toEndWith('&limit=2&sort=-published_at&type=post')
        ->and(Cursor::read((string) $after['after'], '-published_at'))->not->toBeNull()
        ->and($this->getJson('/api/v1/items')->json('links.self'))->toBe('https://example.com/api/v1/items');
});

it('gives an empty list and no next when nothing matches', function (): void {
    ctAdmin();
    Switches::apiOn();

    $this->getJson('/api/v1/items?type=post')->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['limit' => 20], 'links' => ['self' => 'https://example.com/api/v1/items?type=post', 'next' => null]]);
});
