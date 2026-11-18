<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Head\HeadMeta;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\StructuredData\GraphNode;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->faq = ['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [
        ['id' => '01K9F0A0000000000000000Q01', 'question' => 'Is it free?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Yes, always.']]]]]],
    ]]];
    $this->steps = ['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'summary' => 'Brew tea.', 'totalTime' => 'PT10M', 'materials' => ['Tea'], 'tools' => [], 'steps' => [
        ['id' => '01K9F0A0000000000000000S01', 'title' => 'Boil the water', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Fresh water.']]]]], 'mediaId' => null],
    ]]];
});

afterEach(function (): void {
    Seokit::reset();
    PluginFixtures::forget();
});

it('adds the plugin’s tags to the head, name or property and content only, escaped', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news');
    Seokit::$meta = fn (HeadPage $page): array => [HeadMeta::name('robots', 'noindex, "follow"'), HeadMeta::property('og:locale:alternate', 'pt_BR')];

    $dom = headDom('/harbour-news/');

    expect(headValue($dom, '//head/meta[@name="robots"]/@content'))->toBe('noindex, "follow"')
        ->and(headValue($dom, '//head/meta[@property="og:locale:alternate"]/@content'))->toBe('pt_BR')
        ->and((string) addrGet('/harbour-news/')->getContent())->not->toContain('<script>');
});

it('tells the plugin about the page: its kind, address, item and own values, and the cards it holds', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news', nodes: [$this->faq, $this->steps]);

    headDom('/harbour-news/');
    $page = Seokit::$pages[0];

    expect($page->kind)->toBe('article')
        ->and($page->path)->toBe('/harbour-news/')
        ->and($page->address)->toBe('http://localhost/harbour-news/')
        ->and($page->type)->toBe('post')
        ->and($page->title)->toBe('Harbour news')
        ->and($page->faqs)->toHaveCount(1)
        ->and($page->faqs[0]->nodeId)->toBe('http://localhost/harbour-news/#faq-01K9F0A0000000000000000FQ1')
        ->and($page->faqs[0]->pairs[0]->question)->toBe('Is it free?')
        ->and($page->faqs[0]->pairs[0]->answer)->toBe('Yes, always.')
        ->and($page->faqs[0]->pairs[0]->answerHtml)->toBe('<p>Yes, always.</p>')
        ->and($page->steps[0]->totalTime)->toBe('PT10M')
        ->and($page->steps[0]->materials)->toBe(['Tea'])
        ->and($page->steps[0]->steps[0]->position)->toBe(1)
        ->and($page->steps[0]->steps[0]->url)->toBe('http://localhost/harbour-news/#step-01k9f0a0000000000000000s01');
});

it('merges the plugin’s nodes into the graph, using its declared terms', function (): void {
    ['faults' => $faults] = seokitEnabled();
    headPost($this->author, 'Harbour news', nodes: [$this->faq]);
    Seokit::$nodes = fn (HeadPage $page): array => array_map(fn ($faq): GraphNode => new GraphNode([
        '@id' => $faq->nodeId,
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($pair): array => ['@type' => 'Question', 'name' => $pair->question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair->answerHtml]], $faq->pairs),
    ]), $page->faqs);

    expect(sdNode(sdGraph('/harbour-news/'), 'http://localhost/harbour-news/#faq-01K9F0A0000000000000000FQ1'))->toMatchArray([
        '@type' => 'FAQPage',
        'mainEntity' => [['@type' => 'Question', 'name' => 'Is it free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '<p>Yes, always.</p>']]],
    ])->and($faults->seen)->toBe([]);
});

it('leaves out, and records, a node off this site or with terms nobody declared, and keeps the page', function (): void {
    ['faults' => $faults] = seokitEnabled();
    headPost($this->author, 'Harbour news');
    Seokit::$nodes = fn (HeadPage $page): array => [
        new GraphNode(['@id' => 'https://elsewhere.example/#thing', '@type' => 'FAQPage']),
        new GraphNode(['@id' => $page->address.'#recipe', '@type' => 'Recipe', 'name' => 'Soup']),
    ];

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, 'https://elsewhere.example/#thing'))->toBeNull()
        ->and(sdNode($graph, 'http://localhost/harbour-news/#recipe'))->toBeNull()
        ->and(sdNode($graph, 'http://localhost/harbour-news/'))->not->toBeNull()
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#structured-data', 'tests/seokit#structured-data']);
});

it('draws the page without the plugin’s part when the plugin throws, and records the fault', function (): void {
    ['faults' => $faults] = seokitEnabled();
    headPost($this->author, 'Harbour news');
    Seokit::$meta = fn (): array => throw new RuntimeException('Broken');

    expect(headValue(headDom('/harbour-news/'), '//head/title'))->not->toBe('')
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#head-meta']);
});
