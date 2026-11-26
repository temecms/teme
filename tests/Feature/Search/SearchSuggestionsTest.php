<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
});

it('answers up to eight matching items as JSON, each with its title, address and type', function (): void {
    foreach (range(1, 10) as $n) {
        searchPublished($this->author, "Heron watch {$n}", 'The heron stood very still.');
    }

    $response = addrGet('/_teme/suggest?q=heron')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('query'))->toBe('heron')
        ->and($response->json('items'))->toHaveCount(8)
        ->and($response->json('items.0'))->toHaveKeys(['title', 'url', 'type'])
        ->and($response->json('items.0.type'))->toBe('post');
});

it('suggests nothing for a query shorter than two characters', function (): void {
    searchPublished($this->author, 'Heron watch', 'The heron stood very still.');

    expect(addrGet('/_teme/suggest?q=h')->assertOk()->json('items'))->toBe([])
        ->and(addrGet('/_teme/suggest')->assertOk()->json('items'))->toBe([])
        ->and(addrGet('/_teme/suggest?q[]=he')->assertOk()->json('items'))->toBe([]);
});

it('never suggests a draft or a private item, and suggests what the search page lists', function (): void {
    searchPublished($this->author, 'Zqpublic notes', 'zqword');
    Visibilities::change(searchPublished($this->author, 'Zqprivate notes', 'zqword'), 'private', $this->author);
    searchDraft($this->author, 'Zqdraft notes', 'zqword');

    $titles = array_column(addrGet('/_teme/suggest?q=zqword')->assertOk()->json('items'), 'title');
    $page = (string) addrGet('/?s=zqword')->assertOk()->getContent();

    expect($titles)->toBe(['Zqpublic notes'])
        ->and($page)->toContain('Zqpublic notes')->not->toContain('Zqprivate')->not->toContain('Zqdraft');
});

it('suggests in the language asked and never another’s', function (): void {
    LanguageSite::build();

    expect(array_column(addrGet('/_teme/suggest?q=bonjour&lang=fr')->json('items'), 'title'))->toBe(['Bonjour'])
        ->and(addrGet('/_teme/suggest?q=bonjour')->json('items'))->toBe([])
        ->and(addrGet('/_teme/suggest?q=hello&lang=fr')->json('items'))->toBe([])
        ->and(array_column(addrGet('/_teme/suggest?q=hello')->json('items'), 'title'))->toBe(['Hello'])
        // A language that is hidden or unknown is read as the default's.
        ->and(addrGet('/_teme/suggest?q=hola&lang=es')->json('items'))->toBe([])
        ->and(addrGet('/_teme/suggest?q=bonjour&lang=zz')->json('items'))->toBe([]);
});

it('limits asking to sixty answers a minute per reader', function (): void {
    foreach (range(1, 60) as $n) {
        addrGet('/_teme/suggest?q=lifeboat')->assertOk();
    }

    addrGet('/_teme/suggest?q=lifeboat')->assertStatus(429);
});

it('is stored by the page cache for a short time, apart by query and language, and purged when an item changes', function (): void {
    $folder = cacheOn();
    $this->freezeSecond();
    $item = searchPublished($this->author, 'Heron watch', 'The heron stood very still.');

    cacheGet('/_teme/suggest?q=heron')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/_teme/suggest?q=heron')->assertHeader('X-Teme-Cache', 'HIT');
    cacheGet('/_teme/suggest?q=heron&lang=fr')->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/_teme/suggest?q=heron&other=1')->assertHeader('X-Teme-Cache', 'HIT');
    expect(cacheEntry('/_teme/suggest?q=heron')?->expiresAt - now()->getTimestamp())->toBeLessThanOrEqual(60);

    Visibilities::change($item, 'private', $this->author);

    expect(cacheGet('/_teme/suggest?q=heron')->assertHeader('X-Teme-Cache', 'MISS')->json('items'))->toBe([]);

    cacheOff($folder);
});
