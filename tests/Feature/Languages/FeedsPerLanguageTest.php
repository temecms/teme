<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('serves the French feed at /fr/feed/, listing French items and saying fr', function (): void {
    $xml = (string) addrGet('/fr/feed/')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->getContent();

    expect($xml)->toContain('<language>fr</language>')
        ->toContain('<title>Bonjour</title>')
        ->toContain('<link>http://localhost/fr/</link>')
        ->toContain('http://localhost/fr/feed/')
        ->not->toContain('<title>Hello</title>')
        ->not->toContain('<title>Hola</title>');
});

it('serves a French term\'s feed and an author\'s French feed under the prefix', function (): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    $author = addrCurrent('user', (string) $this->site->editor->id);
    $term = addrCurrent('term', $this->site->newsFr);

    expect($term)->toStartWith('fr/');

    foreach (["/{$term}/feed/", "/fr/{$author}/feed/"] as $address) {
        expect((string) addrGet($address)->assertOk()->getContent())
            ->toContain('<language>fr</language>')
            ->toContain('<title>Bonjour</title>')
            ->not->toContain('<title>Hello</title>');
    }

    expect((string) addrGet("/fr/{$author}/feed/")->getContent())->toContain("<link>http://localhost/fr/{$author}/</link>");
});

it('keeps the default language\'s feeds to its own items, saying its language', function (): void {
    expect((string) addrGet('/feed/')->assertOk()->getContent())
        ->toContain('<language>en-GB</language>')
        ->toContain('<title>Hello</title>')
        ->not->toContain('<title>Bonjour</title>');
});

it('answers 404 for a hidden language\'s feed, and for a default term\'s feed under a prefix', function (): void {
    addrGet('/es/feed/')->assertNotFound();
    addrGet('/fr/'.addrCurrent('term', $this->site->news).'/feed/')->assertNotFound();
});

it('links a French page to the French feed, and an English one to the default\'s', function (): void {
    $feed = '//head/link[@rel="alternate"][@type="application/rss+xml"][1]/@href';

    expect(headValue(headDom('/fr/bonjour/'), $feed))->toBe('http://localhost/fr/feed/')
        ->and(headValue(headDom('/hello/'), $feed))->toBe('http://localhost/feed/');
});
