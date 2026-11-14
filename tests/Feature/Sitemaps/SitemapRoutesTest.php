<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Seo\WholeSite;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\SitemapPage;
use App\Domain\Sitemaps\SitemapXml;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
});

it('answers /sitemap.xml with the index as XML, opening with its stylesheet, kept out of search results itself (I-296)', function (): void {
    $this->app['env'] = 'production';

    $response = addrGet('/sitemap.xml')->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, follow');

    expect((string) $response->getContent())->toStartWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?xml-stylesheet type=\"text/xsl\" href=\"/sitemap.xsl\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">")
        ->and(smLocs((string) $response->getContent()))->toBe([
            'http://localhost/sitemap-home.xml',
            'http://localhost/sitemap-items-post.xml',
            'http://localhost/sitemap-terms-category.xml',
            'http://localhost/sitemap-authors.xml',
        ]);
});

it('answers a page as XML, each address with when it last changed and its images', function (): void {
    pageArticle($this->author, 'Pictured story', featured: pageImage($this->author, 800, 600));

    $xml = (string) addrGet('/sitemap-items-post.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    $dom = smParse($xml);

    expect(smLocs($xml))->toBe(['http://localhost/harbour-news/', 'http://localhost/pictured-story/'])
        ->and(headValue($dom, '//s:url[s:loc="http://localhost/pictured-story/"]/s:lastmod'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and((int) $dom->evaluate('count(//s:url[s:loc="http://localhost/pictured-story/"]/image:image/image:loc)'))->toBe(1)
        ->and((int) $dom->evaluate('count(//s:url[s:loc="http://localhost/harbour-news/"]/image:image)'))->toBe(0);
});

it('writes every address so XML reads it back: quotes, ampersands, angle brackets, control characters and a broken byte (I-296)', function (): void {
    $odd = "https://cdn.example/a.webp?w=1&h=2&n='x'<y>\x01".chr(0xC3);
    $xml = SitemapXml::urlset(new SitemapPage([new SitemapAddress('http://localhost/caf%C3%A9/', CarbonImmutable::parse('2020-05-02 10:30:00+00'), [$odd])]));
    $dom = smParse($xml);

    expect(headValue($dom, '//s:url/s:loc'))->toBe('http://localhost/caf%C3%A9/')
        ->and(headValue($dom, '//s:url/s:lastmod'))->toBe('2020-05-02T10:30:00+00:00')
        ->and(headValue($dom, '//image:image/image:loc'))->toBe("https://cdn.example/a.webp?w=1&h=2&n='x'<y>?")
        ->and(SitemapXml::index(['http://localhost/sitemap-a.xml?x=1&y=2']))->toContain('<loc>http://localhost/sitemap-a.xml?x=1&amp;y=2</loc>');
});

it('answers /sitemap.xsl with the stylesheet a browser shows a sitemap through, in the site\'s language, every word written out (I-868)', function (): void {
    $xsl = (string) addrGet('/sitemap.xsl')->assertOk()->assertHeader('Content-Type', 'text/xsl; charset=UTF-8')->getContent();
    $dom = smParse($xsl);
    $dom->registerNamespace('xsl', 'http://www.w3.org/1999/XSL/Transform');
    $text = (string) preg_replace('/\s+/', ' ', (string) $dom->document->textContent);

    expect($dom->evaluate('string(/xsl:stylesheet/@version)'))->toBe('1.0')
        ->and($dom->evaluate('string(//html/@lang)'))->toBe('en')
        ->and($dom->evaluate('string(//html/head/title)'))->toBe('Sitemap')
        ->and($text)->toContain('The lists of this site’s addresses that search engines are asked to read. Each list holds up to 2,000 addresses.')
        ->toContain('These addresses are on this site, and search engines are asked to list them.')
        ->toContain('Search engines read this file as XML. This table shows the same list for people.')
        ->toContain('All lists')
        ->and(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($dom->query('//th') ?: [])))->toBe(['List', 'Address', 'Last changed (UTC)', 'Images'])
        ->and(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($dom->query('//xsl:text') ?: [])))->toBe([' list', ' lists', ' address', ' addresses']);
});

it('sends WordPress\'s and Yoast\'s index addresses to the index', function (string $old): void {
    addrGet($old)->assertStatus(301)->assertHeader('Location', 'http://localhost/sitemap.xml');
})->with(['/wp-sitemap.xml', '/sitemap_index.xml']);

it('answers every sitemap address with the site\'s 404 page while the site discourages search engines (I-291)', function (string $uri): void {
    cmSettings([WholeSite::DISCOURAGE => true], 'seo');

    addrGet($uri)->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
})->with(['/sitemap.xml', '/sitemap-items-post.xml', '/sitemap-home.xml', '/sitemap.xsl', '/wp-sitemap.xml']);

it('answers the site\'s 404 page for a sitemap it doesn\'t have, or a page its index didn\'t give', function (): void {
    addrGet('/sitemap-items-region.xml')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    addrGet('/sitemap-items-post.f0000000000000000000000999.xml')->assertNotFound();
});
