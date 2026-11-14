<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\RenderRecord;
use App\Domain\Feeds\Feed;
use App\Domain\Feeds\FeedBuilder;
use App\Domain\Feeds\FeedItem;
use App\Domain\Feeds\FeedSubject;
use App\Domain\Feeds\Rss2;
use App\Domain\People\Credit;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@private.example'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->site = fn (): Feed => app(FeedBuilder::class)->build(FeedSubject::site());
    $this->titles = fn (Feed $feed): array => array_map(fn (FeedItem $item): string => $item->title, $feed->items);
});

it('holds the newest items first, as many as the setting says, each with its address, author, terms, guid and excerpt', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $tides = typesTerm($this->editor, 'tag', 'Tides');
    pageArticle($this->author, 'First story', categories: [$news]);
    pageArticle($this->author, 'Second story', tags: [$tides]);
    $third = pageArticle($this->author, 'Third story', 50, 'A written excerpt.', [$news], [$tides]);
    cmSettings(['feed_items' => 2], 'seo');

    $feed = ($this->site)();
    $item = $feed->items[0];

    expect(($this->titles)($feed))->toBe(['Third story', 'Second story'])
        ->and($item->url)->toBe(rtrim(url('/'), '/').'/third-story/')
        ->and($item->author)->toBe('Jane Doe')
        ->and($item->categories)->toBe(['News', 'Tides'])
        ->and($item->id)->toBe('teme:content/'.$third->id)
        ->and($item->summary)->toBe('A written excerpt.')
        ->and($item->image)->toBeNull();
});

it('reads the published revision, never a newer draft, and leaves out what is not live', function (): void {
    pageArticle($this->author, 'Public story');
    $edited = pageArticle($this->author, 'Original title');
    pageSaveDraft($edited, 'Secret new title');
    lifecycleDraft($this->author, 'Draft story');
    $trashed = pageArticle($this->author, 'Trashed story');
    pageTrash($trashed, $this->editor);
    $later = pageArticle($this->author, 'Later story');
    DB::table('contents')->where('id', $later->id)->update(['published_at' => now()->addDay()]);

    $feed = ($this->site)();
    $xml = (new Rss2)->render($feed);

    expect(($this->titles)($feed))->toBe(['Original title', 'Public story'])
        ->and($xml)->not->toContain('Secret new title')->not->toContain('Draft story')->not->toContain('Trashed story')->not->toContain('Later story');
});

it('writes the author\'s name and nothing else about them', function (): void {
    pageArticle($this->author, 'Public story');
    $xml = (new Rss2)->render(($this->site)());

    expect(feedParse($xml)->evaluate('string(//item/dc:creator)'))->toBe('Jane Doe')
        ->and($xml)->not->toContain('private.example')->not->toContain('jane@');
});

it('puts the full text in content:encoded and the excerpt in the description, or only the excerpt when the site shows excerpts', function (): void {
    pageArticle($this->author, 'Long story', 80);
    pageArticle($this->author, 'Short story', 50);
    $full = ($this->site)();

    expect($full->items[1]->html)->toBe('<p>'.implode(' ', array_fill(0, 80, 'word')).'</p>')
        ->and($full->items[1]->summary)->toBe(implode(' ', array_fill(0, 55, 'word')).'…')
        ->and($full->items[0]->summary)->toBe(implode(' ', array_fill(0, 50, 'word')));

    cmSettings(['feed_content' => 'excerpt'], 'seo');
    $excerpts = ($this->site)();

    expect($excerpts->items[1]->html)->toBeNull()
        ->and($excerpts->items[1]->summary)->toBe(implode(' ', array_fill(0, 55, 'word')).'…')
        ->and(feedParse((new Rss2)->render($excerpts))->evaluate('count(//item/content:encoded)'))->toBe(0.0);
});

it('gives a featured image as the variant nearest 1,200 pixels wide, at an absolute address', function (): void {
    $media = pageImage($this->author, 1600, 900);
    pageArticle($this->author, 'Plain story');
    pageArticle($this->author, 'With a picture', featured: $media);

    $feed = ($this->site)();
    $image = $feed->items[0]->image;

    expect($image)->not->toBeNull()
        ->and($image?->url)->toStartWith(rtrim(url('/'), '/').'/storage/')
        ->and($image?->type)->toBe('image/webp')
        ->and([$image?->width, $image?->height])->toBe([1280, 720])
        ->and($feed->items[1]->image)->toBeNull();
});

it('makes every address from the current site address and keeps the guid', function (): void {
    $story = pageArticle($this->author, 'Harbour news');
    $before = ($this->site)();

    URL::forceScheme('https');
    URL::forceRootUrl('https://moved.example');
    $after = ($this->site)();

    expect($before->items[0]->url)->not->toStartWith('https://moved.example')
        ->and($after->items[0]->url)->toBe('https://moved.example/harbour-news/')
        ->and($after->selfUrl)->toBe('https://moved.example/feed/')
        ->and($after->link)->toBe('https://moved.example/')
        ->and($after->items[0]->id)->toBe($before->items[0]->id)->toBe('teme:content/'.$story->id);
});

it('stays well-formed for a title and a body full of markup, ]]> and emoji', function (): void {
    $title = "Fish & <Chips> ]]> \u{1F600}";
    pageArticleFromDocument($this->author, $title, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tips ]]> end & <b>bold</b>']]]]);

    $feed = feedParse((new Rss2)->render(($this->site)()));

    expect($feed->evaluate('string(//item/title)'))->toBe($title)
        ->and($feed->evaluate('string(//item/content:encoded)'))->toContain('Tips ]]')->toContain('bold');
});

it('is a valid empty feed for a site with nothing to show, and says when a feed holds something', function (): void {
    $empty = ($this->site)();

    expect($empty->items)->toBe([])
        ->and($empty->updated)->toBeNull()
        ->and(feedParse((new Rss2)->render($empty))->evaluate('count(//item)'))->toBe(0.0);

    pageArticle($this->author, 'Harbour news');

    expect(($this->site)()->updated)->not->toBeNull();
});

it('names the channel after the site, or after the term or author with the site, and describes it', function (): void {
    cmSettings(['site_title' => 'The Ledger', 'tagline' => 'Local news'], 'general');
    $news = typesTerm($this->editor, 'category', 'News');
    $term = new FeedSubject(FeedSubject::TERM, 'News', (string) addrCurrent('term', $news->id), 'All the news.', 'category', $news->id, null);
    $build = app(FeedBuilder::class);

    $site = $build->build(FeedSubject::site());
    $category = $build->build($term);

    expect([$site->title, $site->description, $site->link, $site->selfUrl])->toBe(['The Ledger', 'Local news', rtrim(url('/'), '/').'/', rtrim(url('/'), '/').'/feed/'])
        ->and([$category->title, $category->description, $category->link, $category->selfUrl])
        ->toBe(['News – The Ledger', 'All the news.', rtrim(url('/'), '/').'/category/news/', rtrim(url('/'), '/').'/category/news/feed/']);
});

it('holds a term\'s own items and an author\'s own, and nothing of anyone else\'s', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $bo = User::factory()->create(['name' => 'Bo Reader'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    pageArticle($this->author, 'Jane in news', categories: [$news]);
    pageArticle($this->author, 'Jane elsewhere');
    pageArticle($bo, 'Bo in news', categories: [$news]);

    $term = new FeedSubject(FeedSubject::TERM, 'News', (string) addrCurrent('term', $news->id), null, 'category', $news->id, null);
    $person = new FeedSubject(FeedSubject::AUTHOR, 'Jane Doe', (string) addrCurrent('user', (string) $this->author->id), null, null, null, Credit::account((int) $this->author->id));
    $build = app(FeedBuilder::class);

    expect(($this->titles)($build->build($term)))->toBe(['Bo in news', 'Jane in news'])
        ->and(($this->titles)($build->build($person)))->toBe(['Jane elsewhere', 'Jane in news']);
});

it('records what a feed shows for the page cache: itself, its list, each item, each author and each term', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $story = pageArticle($this->author, 'Harbour news', categories: [$news]);
    app(RenderRecord::class)->start(0);

    ($this->site)();

    expect(app(RenderRecord::class)->tags())->toContain('feed', 'list:post', 'seo', 'content:'.$story->id, 'person:'.$this->author->id, 'term:'.$news->id);
});

it('says noindex, follow, and noindex, nofollow while the site discourages search engines', function (): void {
    expect(($this->site)()->robots)->toBe('noindex, follow');

    cmSettings([WholeSite::DISCOURAGE => true], 'seo');

    expect(($this->site)()->robots)->toBe('noindex, nofollow');
});

it('reads the same number of statements for twelve items as for two', function (): void {
    $media = pageImage($this->author, 800, 600);
    $card = fn (int $n): array => [pageImageNode($media, (string) Str::ulid()), ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Story {$n}"]]]];
    $statements = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->site)();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach ([1, 2] as $n) {
        pageArticleFromDocument($this->author, "Story {$n}", $card($n), $media);
    }

    ($this->site)();
    $two = $statements();

    foreach (range(3, 12) as $n) {
        pageArticleFromDocument($this->author, "Story {$n}", $card($n), $media);
    }

    expect($statements())->toBe($two);
});
