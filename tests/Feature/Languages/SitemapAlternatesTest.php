<?php

declare(strict_types=1);

use App\Domain\Languages\TranslationGroups;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapXml;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * The alternates the sitemap gives an address, as hreflang => address.
 *
 * @return array<string, string>
 */
function saAlternates(string $xml, string $loc): array
{
    $dom = smParse($xml);
    $dom->registerNamespace('xhtml', SitemapXml::XHTML);
    $links = [];

    foreach ($dom->query("//s:url[s:loc='{$loc}']/xhtml:link[@rel='alternate']") ?: [] as $link) {
        /** @var DOMElement $link */
        $links[$link->getAttribute('hreflang')] = $link->getAttribute('href');
    }

    return $links;
}

it('adds the alternates of an item with other versions, x-default included', function (): void {
    $xml = (string) addrGet('/sitemap-items-post.xml')->assertOk()->getContent();
    $expected = ['en-GB' => 'http://localhost/hello/', 'fr' => 'http://localhost/fr/bonjour/', 'x-default' => 'http://localhost/hello/'];

    expect(saAlternates($xml, 'http://localhost/hello/'))->toBe($expected)
        ->and(saAlternates($xml, 'http://localhost/fr/bonjour/'))->toBe($expected)
        ->and(saAlternates($xml, 'http://localhost/alone/'))->toBe([]);
});

it('declares the xhtml namespace once on every page of items', function (): void {
    $xml = (string) addrGet('/sitemap-items-post.xml')->assertOk()->getContent();

    expect(substr_count($xml, 'xmlns:xhtml="'.SitemapXml::XHTML.'"'))->toBe(1)
        ->and($xml)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">');
});

it('lists every shown language\'s items and never a hidden one\'s', function (): void {
    $locs = smLocs((string) addrGet('/sitemap-items-post.xml')->assertOk()->getContent());

    expect($locs)->toContain('http://localhost/hello/', 'http://localhost/fr/bonjour/', 'http://localhost/alone/')
        ->and($locs)->not->toContain('http://localhost/es/hola/');
});

it('lists each shown language\'s home, and each type archive in the languages that have its items', function (): void {
    ctCreate($this->site->admin);
    LanguageSite::version($this->site->editor, 'The film', null, null, [], publish: true, type: 'film');

    $locs = smLocs((string) addrGet('/sitemap-archives.xml')->assertOk()->getContent());

    expect($locs)->toContain('http://localhost/fr/', 'http://localhost/films/')
        ->and($locs)->not->toContain('http://localhost/fr/films/', 'http://localhost/es/');

    LanguageSite::version($this->site->editor, 'Le film', 'fr', null, [], publish: true, type: 'film');

    expect(smLocs((string) addrGet('/sitemap-archives.xml')->assertOk()->getContent()))->toContain('http://localhost/fr/films/');
});

it('adds one statement to a sitemap page, however many groups it lists', function (): void {
    $count = fn (): int => count(planStatements(fn () => addrGet('/sitemap-items-post.xml')->assertOk()));
    // The first answer reads the settings once for the request's process; the counts compared come after it.
    $count();
    $before = $count();

    $group = TranslationGroups::newId();
    LanguageSite::version($this->site->editor, 'Goodbye', null, $group, [], publish: true);
    LanguageSite::version($this->site->editor, 'Au revoir', 'fr', $group, [], publish: true);

    expect($count())->toBe($before);
});

it('lists only the default language\'s items where nothing names a language', function (): void {
    ctCreate($this->site->admin);
    LanguageSite::version($this->site->editor, 'Le film', 'fr', null, [], publish: true, type: 'film');
    $items = app(ListedItems::class);

    expect(array_column($items->latestListed('post', 10), 'id'))->toContain($this->site->post)->not->toContain($this->site->postFr)
        ->and($items->any('film'))->toBeFalse()
        ->and($items->any('film', 'fr'))->toBeTrue();
});
