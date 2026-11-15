<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

/*
 * The leak matrix (I-3360): with French shown, Spanish hidden, a French draft
 * and a French private item, every public answer a reader can reach shows a
 * language's items only where it should, with the page cache off and on, and
 * prints no translation group's ID. Each answer also shows what it should,
 * so an empty answer can't pass.
 */

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->private = LanguageSite::version($this->site->editor, 'Confidentiel', 'fr', null, [], publish: true);
    Visibilities::change(Content::query()->findOrFail($this->private), 'private', $this->site->admin);
    Switches::llmsOn();
    Switches::apiOn();
});

/**
 * Every answer of the matrix: its address, what it must show, and what of
 * the other language it must not.
 *
 * @return list<array{0: string, 1: string, 2: list<string>}>
 */
function lmSurfaces(LanguageSite $site): array
{
    return [
        ['/', 'Hello', ['Bonjour']],
        ['/fr/', 'Bonjour', ['Hello']],
        ['/hello/', 'Hello', []],
        ['/fr/bonjour/', 'Bonjour', []],
        ['/category/news/', 'Hello', ['Bonjour']],
        ['/fr/category/actualites/', 'Bonjour', ['Hello']],
        ['/?s=hello', 'Hello', ['Bonjour']],
        ['/fr/?s=bonjour', 'Bonjour', ['Hello']],
        ['/?s=hola', 'hola', []],
        ['/fr/?s=confidentiel', 'confidentiel', []],
        ['/feed/', 'Hello', ['Bonjour']],
        ['/fr/feed/', 'Bonjour', ['Hello']],
        ['/sitemap.xml', 'sitemap-items-post.xml', []],
        ['/sitemap-items-post.xml', '/fr/bonjour/', []],
        ['/sitemap-items-page.xml', '/about/', []],
        ['/sitemap-terms-category.xml', '/category/news/', []],
        ['/llms.txt', 'Hello', ['Bonjour']],
        ['/hello.md', 'Hello', []],
        ['/fr/bonjour.md', 'Bonjour', []],
        ['/api/v1', 'Français', []],
        ['/api/v1/items', $site->post, [$site->postFr]],
        ['/api/v1/items?language=fr', $site->postFr, [$site->post]],
        ['/api/v1/items/'.$site->post, $site->postFr, []],
        ['/api/v1/items/'.$site->postFr, $site->post, []],
        ['/api/v1/items/by-path?path=/fr/bonjour/', $site->postFr, []],
        ['/api/v1/terms', $site->news, [$site->newsFr]],
        ['/api/v1/terms?language=fr', $site->newsFr, [$site->news]],
        ['/api/v1/terms/'.$site->newsFr, 'actualites', []],
    ];
}

/**
 * What no public answer may hold: the hidden Spanish item, the French draft
 * and the French private item, by title, slug, address and ID, and every
 * translation group's ID.
 *
 * @return list<string>
 */
function lmNever(LanguageSite $site, string $private): array
{
    return [
        'Hola', '/es/', $site->postEs,
        'a-propos', $site->pageFr,
        'Confidentiel', '/fr/confidentiel/', $private,
        $site->postGroup, $site->pageGroup, $site->newsGroup,
    ];
}

/**
 * One answer checked: OK, showing what it should and none of what it mustn't.
 *
 * @param  list<string>  $never
 */
function lmCheck(TestResponse $response, string $address, string $shown, array $never): void
{
    $body = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200, $address)
        ->and($body)->toContain($shown);

    foreach ($never as $needle) {
        expect(str_contains($body, $needle))->toBeFalse("{$address} holds {$needle}");
    }
}

it('shows each language only where it should, with the page cache off', function (): void {
    foreach (lmSurfaces($this->site) as [$address, $shown, $other]) {
        lmCheck(addrGet($address), $address, $shown, [...lmNever($this->site, $this->private), ...$other]);
    }
});

it('shows each language only where it should, with the page cache on, from the cache too', function (): void {
    $folder = cacheOn();

    try {
        foreach (lmSurfaces($this->site) as [$address, $shown, $other]) {
            $never = [...lmNever($this->site, $this->private), ...$other];
            lmCheck(addrGet($address), $address, $shown, $never);
            lmCheck(addrGet($address), $address, $shown, $never);
        }
    } finally {
        File::deleteDirectory($folder);
    }
});

it('answers the hidden language, the draft and the private item as missing pages', function (): void {
    foreach (['/es/', '/es/hola/', '/fr/a-propos/', '/fr/confidentiel/', '/es/feed/'] as $address) {
        expect(addrGet($address)->getStatusCode())->toBe(404, $address);
    }

    $this->getJson('/api/v1/items/'.$this->site->postEs)->assertNotFound();
    $this->getJson('/api/v1/items/'.$this->site->pageFr)->assertNotFound();
    $this->getJson('/api/v1/items?language=es')->assertStatus(400);
});

it('drops a version that is made private from its siblings\' answers at once', function (): void {
    cacheOn();
    lmCheck(addrGet('/hello/'), '/hello/', '/fr/bonjour/', []);

    Visibilities::change(Content::query()->findOrFail($this->site->postFr), 'private', $this->site->admin);

    foreach (['/hello/', '/sitemap-items-post.xml', '/api/v1/items/'.$this->site->post] as $address) {
        expect((string) addrGet($address)->getContent())->not->toContain('/fr/bonjour/', $address);
    }
});
