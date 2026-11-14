<?php

declare(strict_types=1);

use App\Domain\Cards\CardUsage;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author, 'Harbour news');
});

it('draws core\'s robots value right after the title: noindex, follow on a search, noindex, nofollow on a comment\'s reply page (I-291)', function (string $page, string $robots): void {
    $comment = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);
    $uri = $page === 'a search' ? '/?s=harbour' : "/_teme/comment/{$comment->id}/reply";

    $html = (string) addrGet($uri)->assertOk()->getContent();

    expect(substr_count($html, '<meta name="robots"'))->toBe(1)
        ->and($html)->toMatch('~</title>\n    <meta name="robots" content="'.preg_quote($robots, '~').'">\n~');
})->with([
    'a search' => ['a search', 'noindex, follow'],
    'a comment\'s reply page' => ['a comment\'s reply page', 'noindex, nofollow'],
]);

it('writes the head\'s entries right after the title, meta tags then links, each as its own html() writes it (I-228)', function (): void {
    $description = MetaTag::name('description', 'Fish & "chips" <b>tonight</b>');
    $canonical = new LinkTag(LinkRelation::Canonical, 'https://example.test/harbour-news/?a=1&b=2');
    headWith(Precedence::Plugin, [$canonical, $description]);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $head = substr($html, 0, (int) strpos($html, '</head>'));

    expect($html)->toContain("</title>\n    ".$description->html()."\n")
        ->and(substr_count($head, '<link rel="canonical"'))->toBe(1)
        ->and($head)->toContain("\n    ".$canonical->html()."\n")
        ->and((int) strpos($head, $canonical->html()))->toBeGreaterThan((int) strrpos($head, '<meta '));
});

it('keeps the first entry for a key, reading plugin, then site, then core, and contributors of one precedence in the order they were registered (I-228)', function (): void {
    headWith(Precedence::Core, [MetaTag::name('robots', 'core says'), new LinkTag(LinkRelation::Canonical, 'https://example.test/core/')]);
    headWith(Precedence::Site, [MetaTag::name('Robots', 'site says'), MetaTag::name('description', 'site, first'), new LinkTag(LinkRelation::Canonical, 'https://example.test/site/')]);
    headWith(Precedence::Plugin, [MetaTag::name('robots', 'plugin says')]);
    headWith(Precedence::Site, [MetaTag::name('description', 'site, second')]);

    $dom = dtDom((string) addrGet('/harbour-news/')->assertOk()->getContent());
    $robots = '//head/meta[translate(@name, "ROBTS", "robts")="robots"]';

    expect($dom->evaluate("count({$robots})"))->toBe(1.0)
        ->and((string) $dom->evaluate("string({$robots}/@content)"))->toBe('plugin says')
        ->and($dom->evaluate('count(//head/meta[@name="description"])'))->toBe(1.0)
        ->and((string) $dom->evaluate('string(//head/meta[@name="description"]/@content)'))->toBe('site, first')
        ->and($dom->evaluate('count(//head/link[@rel="canonical"])'))->toBe(1.0)
        ->and((string) $dom->evaluate('string(//head/link[@rel="canonical"]/@href)'))->toBe('https://example.test/site/');
});

it('writes one JSON-LD script for the page, in the head after the styles, a contributor\'s nodes merged by @id among the rest (I-698)', function (): void {
    $id = 'https://example.test/#website';
    $graphOf = fn (DOMXPath $dom): array => (array) (json_decode((string) $dom->evaluate('string(//head/script[@type="application/ld+json"])'), true)['@graph'] ?? []);
    $before = $graphOf(dtDom((string) addrGet('/harbour-news/')->assertOk()->getContent()));

    headWith(Precedence::Core, [new JsonLdNode(['@id' => $id, '@type' => 'WebSite', 'name' => 'Core name'])]);
    headWith(Precedence::Plugin, [new JsonLdNode(['@id' => $id, '@type' => 'WebSite', 'name' => '</script><!-- Ledger', 'alternateName' => 'TL'])]);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $dom = dtDom($html);
    $ours = array_values(array_filter($graphOf($dom), fn (array $node): bool => ($node['@id'] ?? null) === $id));

    expect(array_column($before, '@id'))->not->toContain($id)
        ->and($dom->evaluate('count(//script[@type="application/ld+json"])'))->toBe(1.0)
        ->and($dom->evaluate('count(//head/script[@type="application/ld+json"])'))->toBe(1.0)
        ->and((int) strpos($html, 'application/ld+json'))->toBeGreaterThan((int) strpos($html, '<style>@layer'))
        ->and($dom->evaluate('count(//main)'))->toBe(1.0)
        ->and(json_decode((string) $dom->evaluate('string(//head/script[@type="application/ld+json"])'), true)['@context'])->toBe('https://schema.org')
        ->and($ours)->toBe([['@id' => $id, '@type' => 'WebSite', 'name' => '</script><!-- Ledger', 'alternateName' => 'TL']]);
});

it('gathers the head once the template has drawn, so a contributor reads what the page drew, such as a region\'s card', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $region = regionCreate($editor);
    regionSave($region, $editor, [regionCardNode('button')]);
    listsPublish($region, $editor);

    app()->instance('test.head.cards', new class implements HeadContributor
    {
        public function precedence(): Precedence
        {
            return Precedence::Core;
        }

        public function entries(DocumentPage $page): array
        {
            return [MetaTag::name('cards-drawn', implode(' ', app(CardUsage::class)->names()))];
        }
    });
    app()->tag(['test.head.cards'], DocumentMeta::CONTRIBUTORS);

    $dom = dtDom((string) addrGet('/harbour-news/')->assertOk()->getContent());

    expect((string) $dom->evaluate('string(//head/meta[@name="cards-drawn"]/@content)'))->toContain('button');
});
