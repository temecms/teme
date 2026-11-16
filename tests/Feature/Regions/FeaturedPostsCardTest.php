<?php

declare(strict_types=1);

use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\FeaturedPostsCard;
use App\Domain\Contents\Models\Content;
use App\Domain\Languages\PageLanguage;
use App\Domain\Regions\FeaturedPosts;
use App\Domain\Site\ViewModels\ListingItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->folder = null;

    // The titles a list shows, in its order.
    $this->titles = fn (array $attrs): array => array_map(fn (ListingItem $item): string => $item->title, app(FeaturedPosts::class)->items($attrs));

    // The default theme's region after the article, published with one featured posts card: made the first time, saved again after.
    $this->place = function (array $attrs): void {
        $region = Content::query()->where('type', 'region')->where('slug', 'default--after-content')->first() ?? regionCreate($this->admin, 'default');
        regionSave($region, $this->editor, [['type' => 'featured-posts', 'attrs' => ['id' => (string) Str::ulid(), ...$attrs]]]);
        listsPublish($region, $this->editor);
    };
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('keeps the chosen order, and leaves out a draft, a trashed and a private item', function (): void {
    $first = pageArticle($this->editor, 'First');
    $second = pageArticle($this->editor, 'Second');
    $draft = lifecycleDraft($this->editor, 'Only a draft');
    $trashed = pageArticle($this->editor, 'Trashed');
    pageTrash($trashed, $this->editor);
    $private = pageArticle($this->editor, 'Private');
    DB::table('contents')->where('id', $private->id)->update(['visibility' => 'private']);

    expect(($this->titles)(['source' => 'chosen', 'items' => [$second->id, $draft->id, $trashed->id, $private->id, $first->id]]))
        ->toBe(['Second', 'First']);
});

it('leaves out a chosen item dated ahead and one whose type the site does not show', function (): void {
    $shown = pageArticle($this->editor, 'Shown');
    $ahead = pageArticle($this->editor, 'Dated ahead');
    DB::table('contents')->where('id', $ahead->id)->update(['published_at' => now()->addDay()]);
    $hidden = pageArticle($this->editor, 'Hidden type');
    DB::table('contents')->where('id', $hidden->id)->update(['type' => 'region']);

    expect(($this->titles)(['source' => 'chosen', 'items' => [$ahead->id, $hidden->id, $shown->id]]))
        ->toBe(['Shown']);
});

it('lists the newest of the type, newest first, as many as asked', function (): void {
    foreach (['Oldest', 'Older', 'Newer', 'Newest'] as $title) {
        pageArticle($this->editor, $title);
        $this->travel(1)->minutes();
    }
    lifecycleDraft($this->editor, 'Not yet');

    expect(($this->titles)(['source' => 'latest', 'type' => 'post', 'count' => 3]))->toBe(['Newest', 'Newer', 'Older'])
        ->and(($this->titles)(['source' => 'latest', 'type' => 'page', 'count' => 3]))->toBe([])
        ->and(($this->titles)(['source' => 'latest', 'type' => 'region', 'count' => 3]))->toBe([]);
});

it('lists only those filed under the term when one is set, and none for a term since deleted', function (): void {
    $reviews = taxonomyTerm('category', 'Reviews');
    pageArticle($this->editor, 'A review', categories: [$reviews]);
    $this->travel(1)->minutes();
    pageArticle($this->editor, 'News, not filed');
    $gone = taxonomyTerm('category', 'Gone');
    $goneId = $gone->id;
    $gone->delete();

    expect(($this->titles)(['source' => 'latest', 'type' => 'post', 'termId' => $reviews->id]))->toBe(['A review'])
        ->and(($this->titles)(['source' => 'latest', 'type' => 'post', 'termId' => $goneId]))->toBe([]);
});

it('lists the page language\'s posts on a site with several (I-3360)', function (): void {
    $site = LanguageSite::build();

    $default = ($this->titles)(['source' => 'latest', 'type' => 'post', 'count' => 12]);
    app(PageLanguage::class)->use('fr');
    $french = ($this->titles)(['source' => 'latest', 'type' => 'post', 'count' => 12]);

    expect($default)->toContain('Hello')->not->toContain('Bonjour')->not->toContain('Hola')
        ->and($french)->toBe(['Bonjour']);
});

it('records what the list shows by the page cache\'s own tags (I-263)', function (array $attrs, Closure $expected): void {
    $reviews = taxonomyTerm('category', 'Reviews');
    $post = pageArticle($this->editor, 'A review', categories: [$reviews]);
    $record = app(RenderRecord::class);
    $record->start(0);

    app(FeaturedPosts::class)->items([...$attrs, ...(($attrs['source'] ?? '') === 'chosen' ? ['items' => [$post->id]] : ['termId' => $reviews->id])]);

    // Its items' own tags come too (their authors', their kicker's); these are the list's.
    expect($record->tags())->toContain(...$expected($post->id, $reviews->id));

    if (($attrs['source'] ?? '') === 'chosen') {
        // Chosen posts change only with themselves, never with the type's list.
        expect($record->tags())->not->toContain('list:post');
    }
})->with([
    'chosen' => [['source' => 'chosen'], fn (string $post): array => ["content:{$post}"]],
    'latest' => [['source' => 'latest', 'type' => 'post'], fn (string $post, string $term): array => ["content:{$post}", 'list:post', "term:category:{$term}", "term:{$term}"]],
]);

it('sets the page aside when a new post is filed under the term, and the redraw lists it', function (): void {
    $reviews = taxonomyTerm('category', 'Reviews');
    pageArticle($this->editor, 'A story');
    pageArticle($this->editor, 'First review', categories: [$reviews]);
    ($this->place)(['heading' => 'More reviews', 'source' => 'latest', 'type' => 'post', 'termId' => $reviews->id, 'count' => 3]);
    $this->folder = cacheOn();
    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
    $this->travel(1)->minutes();

    pageArticle($this->editor, 'Second review', categories: [$reviews]);

    $redrawn = addrGet('/a-story/')->assertOk();

    expect($redrawn->headers->get('X-Teme-Cache'))->not->toBe('HIT')
        ->and((string) $redrawn->getContent())->toContain('More reviews')->toContain('Second review')->toContain('First review');
});

it('removes the stored page when the region is published again', function (): void {
    pageArticle($this->editor, 'A story');
    ($this->place)(['heading' => 'More to read', 'source' => 'latest', 'type' => 'post', 'count' => 3]);
    $this->folder = cacheOn();
    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    ($this->place)(['heading' => 'Read next', 'source' => 'latest', 'type' => 'post', 'count' => 3]);

    expect(cacheKept('/a-story/'))->toBe([])
        ->and((string) addrGet('/a-story/')->getContent())->toContain('Read next')->not->toContain('More to read');
});

it('draws the list with the theme\'s own view when it has one, and core\'s otherwise', function (): void {
    pageArticle($this->editor, '<b>Bold</b> & co');
    $card = new FeaturedPostsCard;
    $attrs = ['heading' => 'More', 'source' => 'latest', 'type' => 'post', 'count' => 3, 'images' => true];
    $themed = $card->renderHtml($attrs, new RenderContext(app(LinkResolver::class)));

    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $core = $card->renderHtml($attrs, new RenderContext(app(LinkResolver::class)));

    expect($themed)->toContain('<article class="card')->toContain('<h3 class="card-title">')
        ->and($core)->toContain('<ul class="card-featured-posts-list">')->not->toContain('<article class="card')
        ->and($core)->toContain('&lt;b&gt;Bold&lt;/b&gt; &amp; co')->not->toContain('<b>Bold</b>')
        ->and($themed)->not->toContain('<b>Bold</b>');
});

it('draws nothing for an empty list, or outside the web page', function (): void {
    $card = new FeaturedPostsCard;

    expect($card->renderHtml(['source' => 'latest', 'type' => 'post', 'count' => 3], new RenderContext(app(LinkResolver::class))))->toBe('');

    pageArticle($this->editor, 'A story');

    expect($card->renderHtml(['source' => 'latest', 'type' => 'post', 'count' => 3], new RenderContext(app(LinkResolver::class), target: 'feed')))->toBe('');
});

it('shows the list after the article, with its heading', function (): void {
    pageArticle($this->editor, 'A story');
    $this->travel(1)->minutes();
    pageArticle($this->editor, 'Another story');
    ($this->place)(['heading' => 'More to read', 'source' => 'latest', 'type' => 'post', 'count' => 2]);

    expect((string) addrGet('/a-story/')->assertOk()->getContent())
        ->toContain('data-region="after-content"')->toContain('More to read')->toContain('Another story');
});

it('costs the same number of queries for one item as for twelve', function (): void {
    foreach (range(1, 12) as $number) {
        pageArticle($this->editor, "Story {$number}");
    }
    $queries = function (int $count): int {
        $asked = 0;
        DB::listen(function (QueryExecuted $query) use (&$asked): void {
            $asked++;
        });
        $before = $asked;
        app(FeaturedPosts::class)->items(['source' => 'latest', 'type' => 'post', 'count' => $count]);

        return $asked - $before;
    };

    // Once first, so what is read once a request is already read.
    app(FeaturedPosts::class)->items(['source' => 'latest', 'type' => 'post', 'count' => 1]);
    $one = $queries(1);
    $twelve = $queries(12);

    expect($twelve)->toBe($one);
});

it('keeps the default theme\'s floor with both cards after the article: one h1, headings in order, every picture sized and described', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $story = pageArticle($this->editor, 'A story');
    $this->travel(1)->minutes();
    pageArticle($this->editor, 'Another story');
    $entry = scAdd($this->admin, 'Sidebar ad', 'placed', '<div data-test="placed-ad">Ad</div>');
    $region = regionCreate($this->admin, 'default');
    regionSave($region, $this->editor, [
        ['type' => 'featured-posts', 'attrs' => ['id' => (string) Str::ulid(), 'heading' => 'More to read', 'source' => 'latest', 'type' => 'post', 'count' => 3]],
        ['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'entryId' => $entry->id]],
    ]);
    listsPublish($region, $this->editor);

    $html = (string) addrGet('/a-story/')->assertOk()->getContent();
    $dom = dtDom($html);

    expect($html)->toContain('More to read')->toContain('data-test="placed-ad"')
        ->and($dom->query('//h1')->length)->toBe(1)
        ->and($dom->query('//img[not(@alt)]')->length)->toBe(0)
        ->and($dom->query('//img[not(@width) or not(@height)]')->length)->toBe(0)
        ->and(dtHeadingsInOrder($dom))->toBeTrue()
        ->and($story->id)->not->toBe('');
});
