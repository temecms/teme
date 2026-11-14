<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Site\ReaderKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

/**
 * What a 404 for an ID tells, with the ID itself taken out: its status,
 * body and headers but the date and the requests left.
 *
 * @return array{status: int, body: string, headers: array<string, list<string|null>>}
 */
function leakAnswer(TestResponse $response, string $id): array
{
    $headers = $response->headers->all();
    // The date, and the client's own count of requests left, change with every request, whatever it asks.
    unset($headers['date'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return ['status' => $response->getStatusCode(), 'body' => str_replace($id, '{id}', (string) $response->getContent()), 'headers' => $headers];
}

it('answers 404 for an item in every state a reader can\'t see, as for one that never existed', function (): void {
    $site = $this->site;
    $fresh = strtolower((string) Str::ulid());
    $never = leakAnswer($this->getJson('/api/v1/items/'.$fresh), $fresh);
    $states = [
        'private' => $site->private,
        'draft' => $site->draft,
        'scheduled' => $site->scheduled,
        'trashed' => $site->trashed,
        'region' => $site->region,
        'dated ahead' => $site->ahead,
        'of a type no longer public' => $site->retired,
    ];

    expect($never['status'])->toBe(404)
        ->and($never['body'])->toContain('"code":"not_found"')
        ->and($never['headers'])->not->toHaveKey('etag')
        ->not->toHaveKey('set-cookie');

    foreach ($states as $state => $item) {
        /** @var Content $item */
        expect(leakAnswer($this->getJson('/api/v1/items/'.$item->id), $item->id))->toBe($never, $state);
    }
});

it('leaves every such item out of every list and filter', function (): void {
    $site = $this->site;
    $hidden = [$site->private->id, $site->draft->id, $site->scheduled->id, $site->trashed->id, $site->region->id, $site->ahead->id, $site->retired->id];
    $slug = (string) DB::table('users')->where('id', $site->author->id)->value('slug');
    $lists = [
        '/api/v1/items?limit=100',
        '/api/v1/items?limit=100&sort=published_at',
        '/api/v1/items?limit=100&type=movie',
        '/api/v1/items?limit=100&type=movie&sort=published_at',
        '/api/v1/items?limit=100&term='.$site->genre->id,
        '/api/v1/items?limit=100&author='.$slug,
        '/api/v1/items?limit=100&author='.$slug.'&sort=published_at',
    ];

    foreach ($lists as $list) {
        $response = $this->getJson($list)->assertOk();
        $ids = array_column($response->json('data'), 'id');

        expect($ids)->not->toBe([], $list)
            ->and(array_intersect($ids, $hidden))->toBe([], $list)
            ->and((string) $response->getContent())->not->toContain('SECRET');
    }
});

it('answers exactly the items the public site answers', function (): void {
    $site = $this->site;
    $items = [
        $site->public, $site->sequel, $site->protected, $site->private, $site->draft, $site->newerDraft,
        $site->scheduled, $site->trashed, $site->region, $site->about, $site->ahead, $site->pointer,
    ];

    foreach ($items as $item) {
        /** @var Content $item */
        $path = Addresses::relativeUrl($item->refresh());
        $site200 = $path !== null && addrGet($path)->getStatusCode() === 200;
        $api = $this->getJson('/api/v1/items/'.$item->id);

        expect($api->getStatusCode() === 200)->toBe($site200, (string) $item->title);
    }

    // The protected item: the site's form, the API's item without its words.
    $form = (string) addrGet((string) Addresses::relativeUrl($site->protected))->getContent();

    expect($form)->toContain('action="/_teme/unlock"')
        ->and($this->getJson('/api/v1/items/'.$site->protected->id)->json('data.body'))->toBeNull();
});

it('gives a protected item\'s title and none of its words, excerpt, image, fields or password, whatever cookie the reader sends', function (): void {
    $site = $this->site;
    $key = Visibilities::cookie($site->protected);
    $address = (string) Addresses::relativeUrl($site->protected);

    // The key opens the page on the site.
    expect((string) cacheGet($address, [ReaderKeys::COOKIE => $key])->getContent())->toContain(DeliverySite::PROTECTED_WORDS);

    foreach ([null, $key, 'not a key'] as $cookie) {
        $request = $cookie === null ? $this : $this->withCredentials()->withUnencryptedCookie(ReaderKeys::COOKIE, $cookie);
        $response = $request->getJson('/api/v1/items/'.$site->protected->id)->assertOk();
        $answer = (string) $response->getContent();

        expect($response->json('data.title'))->toBe('Locked')
            ->and($answer)->not->toContain(DeliverySite::PROTECTED_WORDS)
            ->not->toContain(DeliverySite::PROTECTED_TAGLINE)
            ->not->toContain('What the password keeps.')
            ->not->toContain($site->protectedOnly->id)
            ->not->toContain(DeliverySite::PASSWORD);
    }
});

it('lists a protected item with its title, terms, author and date only', function (): void {
    $site = $this->site;
    $response = $this->getJson('/api/v1/items?limit=100')->assertOk();
    $locked = collect($response->json('data'))->firstWhere('id', $site->protected->id);

    expect($locked['title'])->toBe('Locked')
        ->and($locked['visibility'])->toBe('protected')
        ->and($locked['excerpt'])->toBeNull()
        ->and($locked['featured_image'])->toBeNull()
        ->and($locked['author']['name'])->toBe('Ada Obi')
        ->and($locked['published_at'])->not->toBeNull()
        ->and($locked['terms'])->toBe(['genre' => []])
        ->and((string) $response->getContent())->not->toContain('What the password keeps.')
        ->not->toContain($site->protectedOnly->id);
});

it('gives a byline with no email, role or ID', function (): void {
    $site = $this->site;
    $answers = [
        (string) $this->getJson('/api/v1/items?limit=100')->getContent(),
        (string) $this->getJson('/api/v1/items/'.$site->public->id)->getContent(),
    ];
    $author = $this->getJson('/api/v1/items/'.$site->public->id)->json('data.author');

    expect(array_keys($author))->toEqualCanonicalizing(['name', 'slug', 'path', 'url', 'bio'])
        ->and($author['name'])->toBe('Ada Obi')
        ->and($author['bio'])->toBe('Film editor.');

    foreach ($answers as $answer) {
        expect($answer)->not->toContain('ada@example.com')
            ->not->toContain('"author_id"')
            ->not->toContain('"role"')
            ->not->toContain('"email"')
            ->not->toContain('"id":'.$site->author->id.',')
            ->not->toContain('"id":"'.$site->author->id.'"');
    }
});

it('never shows a taxonomy that isn\'t public on an item', function (): void {
    $site = $this->site;
    $response = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk();

    expect(array_keys($response->json('data.terms')))->toBe(['genre'])
        ->and((string) $response->getContent())->not->toContain($site->shelf->id)
        ->not->toContain('Top shelf')
        ->not->toContain('"shelf"');
});
