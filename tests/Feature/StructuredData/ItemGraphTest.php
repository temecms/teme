<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\Head\PageHead;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\StructuredData\CoreGraph;
use App\Domain\StructuredData\ItemNodes;
use App\Domain\StructuredData\TypeSchema;
use App\Domain\Themes\ViewIntent;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->author->forceFill(['bio' => 'Covers the harbour board.'])->save();
    app(PathProjection::class)->syncUser($this->author);
    $this->page = 'http://localhost/harbour-news/';
    $this->widest = function (Media $media): array {
        $widths = array_filter((array) $media->refresh()->variants, fn (string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY);
        uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return reset($widths);
    };
});

it('describes a post as a web page holding a blog post, part of the site, its author a person with their own @id (I-696, I-699, I-701)', function (): void {
    $news = headTerm('category', 'News');
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::META_DESCRIPTION => 'What the harbour board decided.'], [$news, headTerm('tag', 'Tides'), headTerm('tag', 'Ferries')]);
    $person = 'http://localhost/author/jane-doe/#person';

    $graph = sdGraph('/harbour-news/');

    expect(array_column($graph, '@id'))->toBe(['http://localhost/#website', $this->page, $this->page.'#article', $person])
        ->and(sdNode($graph, 'http://localhost/#website'))->toEqual(['@id' => 'http://localhost/#website', '@type' => 'WebSite', 'url' => 'http://localhost/', 'name' => pageSiteName(), 'inLanguage' => 'en'])
        ->and(sdNode($graph, $this->page))->toMatchArray([
            '@type' => 'WebPage',
            'url' => $this->page,
            'name' => 'Harbour news – '.pageSiteName(),
            'description' => 'What the harbour board decided.',
            'isPartOf' => ['@id' => 'http://localhost/#website'],
            'inLanguage' => 'en',
        ])
        ->and(sdNode($graph, $this->page.'#article'))->toMatchArray([
            '@type' => 'BlogPosting',
            'headline' => 'Harbour news',
            'isPartOf' => ['@id' => $this->page],
            'mainEntityOfPage' => ['@id' => $this->page],
            'author' => [['@id' => $person]],
            'keywords' => ['Tides', 'Ferries'],
            'articleSection' => 'News',
            'wordCount' => (int) DB::table('content_revisions')->where('id', $post->published_revision_id)->value('word_count'),
            'inLanguage' => 'en',
        ])
        ->and(sdNode($graph, $person))->toEqual(['@id' => $person, '@type' => 'Person', 'name' => 'Jane Doe', 'url' => 'http://localhost/author/jane-doe/', 'description' => 'Covers the harbour board.']);
});

it('describes a page as a web page with no main entity, naming no person (I-701)', function (): void {
    addrPublished(User::factory()->create()->assignRole('editor'), 'About us', 'page');

    $graph = sdGraph('/about-us/');

    expect(array_column($graph, '@id'))->toBe(['http://localhost/#website', 'http://localhost/about-us/'])
        ->and(sdNode($graph, 'http://localhost/about-us/')['@type'])->toBe('WebPage');
});

it('dates an item in the site\'s time zone with its offset: its first publication, and the shown revision\'s time, never before it (I-699)', function (): void {
    cmSettings(['timezone' => 'Europe/Paris'], 'general');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    $post = headPost($this->author, 'Harbour news');
    $first = sdNode(sdGraph('/harbour-news/'), $this->page.'#article');

    $this->travelTo(CarbonImmutable::parse('2026-10-10 15:30:00', 'UTC'));
    docSave($post, $this->author, (array) ContentRevision::query()->findOrFail($post->working_revision_id)->document);
    listsPublish($post, $this->author);
    $graph = sdGraph('/harbour-news/');

    expect([$first['datePublished'], $first['dateModified']])->toBe(['2026-10-08T11:00:00+02:00', '2026-10-08T11:00:00+02:00'])
        ->and(sdNode($graph, $this->page.'#article'))->toMatchArray(['datePublished' => '2026-10-08T11:00:00+02:00', 'dateModified' => '2026-10-10T17:30:00+02:00'])
        ->and(sdNode($graph, $this->page))->toMatchArray(['datePublished' => '2026-10-08T11:00:00+02:00', 'dateModified' => '2026-10-10T17:30:00+02:00']);
});

it('heads the article with the item\'s own title, and names the page by its document title, an SEO title word for word (I-699)', function (): void {
    headPost($this->author, 'Ferry fares', [BuiltInFields::SEO_TITLE => 'Ferry fares rise again | {site}']);

    $graph = sdGraph('/ferry-fares/');

    expect(sdNode($graph, 'http://localhost/ferry-fares/')['name'])->toBe('Ferry fares rise again | {site}')
        ->and(sdNode($graph, 'http://localhost/ferry-fares/#article')['headline'])->toBe('Ferry fares');
});

it('describes the primary image at its widest size with its caption and credit, never its alt text (I-700)', function (string $source): void {
    $picture = pageImage($this->author, 1600, 900);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($picture->id, (string) $this->author->id, $picture->title, 'Boats in the harbour', 'Boats at dawn', 'Harbour Trust'));
    $featured = $source === 'the featured image' ? $picture : null;
    $nodes = $source === 'the body\'s first image' ? [pageImageNode($picture, '01K6A0P1CE000000000000MG01')] : [];
    headPost($this->author, 'Harbour news', [], [], $nodes, $featured);
    $widest = ($this->widest)($picture);
    $url = url(MediaDisks::public()->url($widest['path']));

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#primaryimage'))->toEqual([
        '@id' => $this->page.'#primaryimage', '@type' => 'ImageObject', 'url' => $url, 'contentUrl' => $url,
        'width' => $widest['width'], 'height' => $widest['height'], 'caption' => 'Boats at dawn', 'creditText' => 'Harbour Trust', 'inLanguage' => 'en',
    ])
        ->and(sdNode($graph, $this->page)['primaryImageOfPage'])->toBe(['@id' => $this->page.'#primaryimage'])
        // First: a featured image's three crops follow it once they are made (I-855),
        // which the crops test holds.
        ->and(sdNode($graph, $this->page.'#article')['image'][0])->toBe(['@id' => $this->page.'#primaryimage']);
})->with(['the featured image', 'the body\'s first image']);

it('never takes the social image as the primary image (I-700)', function (): void {
    $picture = pageImage($this->author, 1600, 900);
    headPost($this->author, 'Harbour news', [BuiltInFields::SOCIAL_IMAGE => ['id' => $picture->id, 'alt' => null, 'decorative' => false]]);

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#primaryimage'))->toBeNull()
        ->and(sdNode($graph, $this->page))->not->toHaveKey('primaryImageOfPage')
        ->and(sdNode($graph, $this->page.'#article'))->not->toHaveKey('image');
});

it('lists the three 1,200-pixel crops after the primary image in the article\'s image, when the image has them (I-700)', function (): void {
    $picture = pageImage($this->author, 1600, 900);
    headPost($this->author, 'Harbour news', featured: $picture);
    $variants = (array) $picture->refresh()->variants;

    foreach (['crop-16x9' => [1200, 675], 'crop-4x3' => [1200, 900], 'crop-1x1' => [900, 900]] as $key => [$width, $height]) {
        $variants[$key] = ['path' => "crops/{$key}.webp", 'width' => $width, 'height' => $height, 'bytes' => 1];
    }

    DB::table('media')->where('id', $picture->id)->update(['variants' => json_encode($variants)]);

    expect(sdNode(sdGraph('/harbour-news/'), $this->page.'#article')['image'])->toBe([
        ['@id' => $this->page.'#primaryimage'],
        url(MediaDisks::public()->url('crops/crop-16x9.webp')),
        url(MediaDisks::public()->url('crops/crop-4x3.webp')),
        url(MediaDisks::public()->url('crops/crop-1x1.webp')),
    ]);
});

it('drops the primary image, and every reference to it, once the image is trashed (I-700)', function (): void {
    $picture = pageImage($this->author, 1600, 900);
    headPost($this->author, 'Harbour news', featured: $picture);
    app(TrashMedia::class)->execute(new MediaActionInput($picture->id, (string) $this->author->id));

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#primaryimage'))->toBeNull()
        ->and(sdNode($graph, $this->page))->not->toHaveKey('primaryImageOfPage')
        ->and(sdNode($graph, $this->page.'#article'))->not->toHaveKey('image');
});

it('names an author with no address by name alone, inside the article (I-699)', function (): void {
    headPost(User::factory()->create(['name' => 'Ada Obi'])->assignRole('author'), 'Harbour news');

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#article')['author'])->toBe([['@type' => 'Person', 'name' => 'Ada Obi']])
        ->and(array_filter($graph, fn (array $node): bool => $node['@type'] === 'Person'))->toBe([]);
});

it('describes the page shown at the site\'s address at the site\'s address (I-696)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $welcome = addrPublished($editor, 'Welcome', 'page');
    addrChange(User::factory()->create()->assignRole('administrator'), ['frontShows' => 'page', 'frontPageId' => $welcome->id]);

    $graph = sdGraph('/');

    expect(array_column($graph, '@id'))->toBe(['http://localhost/#website', 'http://localhost/'])
        ->and(sdNode($graph, 'http://localhost/'))->toMatchArray(['@type' => 'WebPage', 'url' => 'http://localhost/']);
});

it('describes the site alone on a preview of an item with no address yet, and a published item\'s preview from the revision it shows (I-701, I-900)', function (): void {
    $draft = lifecycleDraft($this->author, 'Ferry fares');
    $post = headPost($this->author, 'Harbour news');
    pageSaveDraft($post, 'Harbour news, updated');

    expect(array_column(sdGraph('/preview/'.histPreview($draft, $this->author)->token), '@id'))->toBe(['http://localhost/#website'])
        ->and(sdNode(sdGraph('/preview/'.histPreview($post->refresh(), $this->author)->token), $this->page.'#article')['headline'])->toBe('Harbour news, updated')
        ->and(sdNode(sdGraph('/harbour-news/'), $this->page.'#article')['headline'])->toBe('Harbour news');
});

it('gives a main entity that isn\'t an article its name alone, for its type\'s map to say the rest (I-709)', function (): void {
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('talk', 'Talk', 'Talks', 'talks', schema: new TypeSchema(mainEntity: 'Event', mainEntities: ['Event'])));
    $view = new ArticleView(
        id: '01k6a0p1ce000000000000tk01', type: 'talk', title: 'Harbour talk', url: '/talks/harbour-talk/', template: null, standfirst: null, kicker: null,
        authors: [], published: new DateTimeImmutable('2026-10-08T09:00:00+00:00'), updated: null, publishedText: '8 October 2026', updatedText: null,
        readingMinutes: 1, lead: null, leadIsFeatured: false, bodyHtml: '', tags: [], related: [],
    );
    $values = new HeadValues('Harbour talk', null, 'http://localhost/talks/harbour-talk/', null, [], null, null, 'article', null, null, null);

    $nodes = array_map(fn (JsonLdNode $node): array => $node->properties, app(ItemNodes::class)->for($view, $values));

    expect(sdNode($nodes, 'http://localhost/talks/harbour-talk/#article'))->toBe(['@id' => 'http://localhost/talks/harbour-talk/#article', '@type' => 'Event', 'name' => 'Harbour talk', 'mainEntityOfPage' => ['@id' => 'http://localhost/talks/harbour-talk/']]);
});

it('reads one statement for an item\'s primary image\'s row, none without an image, and one for its cards\' nodes (I-701, I-704)', function (): void {
    $pictured = headPost($this->author, 'Harbour news', featured: pageImage($this->author, 1600, 900));
    $plain = headPost($this->author, 'Ferry fares');
    $statements = function (Content $item): array {
        $page = new DocumentPage(new ViewIntent('single', type: 'post'), app(ArticleViews::class)->published($item));
        app(PageHead::class)->for($page);
        // Once first, so the settings the graph reads are already held.
        app(CoreGraph::class)->entries($page);

        return dashQueries(fn () => app(CoreGraph::class)->entries($page));
    };
    $from = fn (array $statements, string $table): int => count(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));

    $withImage = $statements($pictured);
    $without = $statements($plain);

    expect($withImage)->toHaveCount(2)
        ->and($from($withImage, 'media'))->toBe(1)
        ->and($from($withImage, 'content_revisions'))->toBe(1)
        ->and($without)->toHaveCount(1)
        ->and($from($without, 'content_revisions'))->toBe(1);
});
