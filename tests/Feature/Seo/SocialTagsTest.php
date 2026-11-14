<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->site = pageSiteName();
    $this->tags = function (string $uri, int $status = 200): array {
        $dom = headDom($uri, $status);
        $tags = [];

        foreach (['og:type', 'og:title', 'og:description', 'og:url', 'og:site_name', 'og:image', 'og:image:width', 'og:image:height', 'og:image:alt'] as $property) {
            $tags[$property] = headValue($dom, "//head/meta[@property=\"{$property}\"]/@content");
        }

        $tags['twitter:card'] = headValue($dom, '//head/meta[@name="twitter:card"]/@content');
        $tags['count'] = (int) $dom->evaluate('count(//head/meta[starts-with(@property, "og:")] | //head/meta[@name="twitter:card"])');

        return $tags;
    };
    // The file the card shows: the 1200×630 card once made, else the widest size (I-855).
    $this->served = function (Media $media): array {
        $variants = $media->refresh()->variants;
        $widths = array_filter($variants, fn (string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY);
        uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return $variants['social'] ?? reset($widths);
    };
});

it('writes Open Graph and twitter:card for an item from its own social fields, escaped as typed (I-293)', function (): void {
    $picture = pageImage($this->author, 1600, 900);
    headPost($this->author, 'Ferry fares', [
        BuiltInFields::SOCIAL_TITLE => 'Fares rise "again" & <soon>',
        BuiltInFields::SOCIAL_DESCRIPTION => 'What commuters will pay.',
        BuiltInFields::SOCIAL_IMAGE => ['id' => $picture->id, 'alt' => 'The ferry at dawn', 'decorative' => false],
    ]);
    $served = ($this->served)($picture);
    $tags = ($this->tags)('/ferry-fares/');

    expect($tags)->toMatchArray([
        'og:type' => 'article',
        'og:title' => 'Fares rise "again" & <soon>',
        'og:description' => 'What commuters will pay.',
        'og:url' => 'http://localhost/ferry-fares/',
        'og:site_name' => $this->site,
        'og:image:width' => (string) $served['width'],
        'og:image:height' => (string) $served['height'],
        'og:image:alt' => 'The ferry at dawn',
        'twitter:card' => 'summary_large_image',
    ])
        ->and($tags['og:image'])->toStartWith('http')->toEndWith($served['path'])
        ->and((string) addrGet('/ferry-fares/')->getContent())->toContain('<meta property="og:title" content="Fares rise &quot;again&quot; &amp; &lt;soon&gt;">');
});

it('falls back from the social title and description to the SEO ones, then to the title and excerpt (I-293)', function (string $case, string $title, string $description): void {
    headPost($this->author, 'Harbour news', match ($case) {
        'the SEO title and meta description' => [BuiltInFields::SEO_TITLE => 'Harbour board decides', BuiltInFields::META_DESCRIPTION => 'What the board decided.'],
        'the title and excerpt' => [BuiltInFields::EXCERPT => 'The board met on Tuesday.'],
        default => [],
    });

    expect(($this->tags)('/harbour-news/'))->toMatchArray(['og:title' => $title, 'og:description' => $description]);
})->with([
    'the SEO title and meta description' => ['the SEO title and meta description', 'Harbour board decides', 'What the board decided.'],
    'the title and excerpt' => ['the title and excerpt', 'Harbour news', 'The board met on Tuesday.'],
    'the title alone' => ['the title alone', 'Harbour news', ''],
]);

it('falls back from the social image to the featured image, then to the first image in the body, wherever it sits, then to none (I-293)', function (string $case): void {
    $featured = pageImage($this->author, 1400, 900);
    $inline = pageImage($this->author, 1200, 800);
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The ferry leaves at six.']]];

    match ($case) {
        'the featured image' => headPost($this->author, 'Pictured', [], [], [$paragraph, pageImageNode($inline, (string) Str::ulid())], $featured),
        'the first image in the body' => headPost($this->author, 'Pictured', [], [], [$paragraph, pageImageNode($inline, (string) Str::ulid())]),
        default => headPost($this->author, 'Pictured'),
    };

    $tags = ($this->tags)('/pictured/');

    match ($case) {
        'the featured image' => expect($tags['og:image'])->toEndWith(($this->served)($featured)['path']),
        'the first image in the body' => expect($tags['og:image'])->toEndWith(($this->served)($inline)['path']),
        default => expect([$tags['og:image'], $tags['og:image:width'], $tags['twitter:card']])->toBe(['', '', 'summary_large_image']),
    };
})->with(['the featured image', 'the first image in the body', 'no image']);

it('describes the home page and an archive as a website, and draws no social tags on a search, a 404 or core\'s reply page (I-293)', function (): void {
    $news = headTerm('category', 'News');
    headPost($this->author, 'Harbour news', [], [$news]);
    $comment = cmComment(cmItem($this->author, 'Harbour notes'), attributes: ['author_name' => 'Bo Reader']);

    expect(($this->tags)('/'))->toMatchArray(['og:type' => 'website', 'og:title' => $this->site, 'og:url' => 'http://localhost/'])
        ->and(($this->tags)('/category/news/'))->toMatchArray(['og:type' => 'website', 'og:title' => 'News', 'og:url' => 'http://localhost/category/news/'])
        ->and(($this->tags)('/?s=harbour')['count'])->toBe(0)
        ->and(($this->tags)('/no-such-page/', 404)['count'])->toBe(0)
        ->and(($this->tags)("/_teme/comment/{$comment->id}/reply")['count'])->toBe(0);
});
