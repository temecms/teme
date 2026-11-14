<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\MediaDisks;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\SitemapPage;
use App\Domain\Sitemaps\SitemapProvider;
use App\Domain\Sitemaps\Sitemaps;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    $this->locs = fn (?SitemapPage $page): ?array => $page === null ? null : array_map(fn (SitemapAddress $address): string => $address->loc, $page->addresses);
});

it('lists every child\'s first page: the site\'s address, each type with live items, each taxonomy with listed terms, then the authors (I-296)', function (): void {
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    addrPublished($this->admin, 'About', 'page');
    typesTerm($this->editor, 'tag', 'Unused');

    expect(app(Sitemaps::class)->index())->toBe([
        'http://localhost/sitemap-home.xml',
        'http://localhost/sitemap-items-post.xml',
        'http://localhost/sitemap-items-page.xml',
        'http://localhost/sitemap-terms-category.xml',
        'http://localhost/sitemap-authors.xml',
    ]);
});

it('gives each later page of a child an address of its own, from the key the index found it starts at', function (): void {
    pageArticle($this->author, 'Harbour news');
    planFillerPosts($this->author, 2000);
    $starts = app(ListedItems::class)->starts('post');

    expect($starts)->toHaveCount(1)
        ->and(app(Sitemaps::class)->index())->toContain('http://localhost/sitemap-items-post.xml', "http://localhost/sitemap-items-post.{$starts[0]}.xml")
        ->and(($this->locs)(app(Sitemaps::class)->page('items-post')))->toBe(['http://localhost/harbour-news/']);
});

it('answers only the pages the index gives: a key it didn\'t give, a key of the wrong shape, a child it doesn\'t have', function (string $file): void {
    pageArticle($this->author, 'Harbour news');
    planFillerPosts($this->author, 2000);
    app(Sitemaps::class)->index();

    expect(app(Sitemaps::class)->page($file))->toBeNull();
})->with([
    'a key it didn\'t give' => 'items-post.f0000000000000000000000999',
    'a key in capitals' => 'items-post.F0000000000000000000002000',
    'a dot and no key' => 'items-post.',
    'a type that isn\'t public' => 'items-region',
]);

it('leaves a child out of the index, and answers none of its pages, while Search and social hides what it lists (I-291)', function (string $setting, string $name): void {
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    cmSettings([$setting => true], 'seo');

    expect(app(Sitemaps::class)->index())->not->toContain("http://localhost/sitemap-{$name}.xml")
        ->and(app(Sitemaps::class)->page($name))->toBeNull();
})->with([
    'posts' => ['hide_type_post', 'items-post'],
    'category archives' => ['hide_taxonomy_category', 'terms-category'],
    'author archives' => ['hide_author', 'authors'],
]);

it('lists the terms that hold live posts, at their archives\' addresses, in ID order, each page carrying its terms\' tags', function (): void {
    $sport = typesTerm($this->editor, 'category', 'Sport');
    $empty = typesTerm($this->editor, 'category', 'Empty');
    pageArticle($this->author, 'Match report', categories: [$sport]);
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    typesDraft($this->author, 'post', 'Not yet', [$empty->id]);

    $page = app(Sitemaps::class)->page('terms-category');

    expect(($this->locs)($page))->toBe(['http://localhost/category/news/', 'http://localhost/category/sport/'])
        ->and($page?->tags)->toBe(['term:'.$this->news->id, 'term:'.$sport->id]);
});

it('lists the people with live posts, at their archives\' addresses, and nobody whose only work is a page or a draft', function (): void {
    $writer = User::factory()->create(['name' => 'Bo Writer'])->assignRole('author');
    app(PathProjection::class)->syncUser($writer);
    app(PathProjection::class)->syncUser($this->admin);
    pageArticle($this->author, 'Harbour news');
    lifecycleDraft($writer, 'Not yet');
    addrPublished($this->admin, 'About', 'page');

    expect(($this->locs)(app(Sitemaps::class)->page('authors')))->toBe(['http://localhost/author/jane-doe/']);
});

it('lists the site\'s address, dated by its newest post while it shows the latest, as the page it shows otherwise, and not at all while that page is hidden (I-854)', function (): void {
    pageArticle($this->author, 'Harbour news');
    $front = addrPublished($this->admin, 'Welcome', 'page');
    $home = fn (): ?SitemapAddress => app(Sitemaps::class)->page('home')?->addresses[0] ?? null;

    expect($home()?->loc)->toBe('http://localhost/')
        ->and($home()?->lastmod?->equalTo(app(ListedItems::class)->newest('post')))->toBeTrue();

    addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $front->id]);

    expect($home()?->loc)->toBe('http://localhost/')
        ->and($home()?->lastmod?->equalTo(app(ListedItems::class)->listed($front->id)))->toBeTrue()
        ->and(($this->locs)(app(Sitemaps::class)->page('items-page')))->toBe([]);

    docSave($front, $this->admin, docWith(docText()), [BuiltInFields::NOINDEX => true]);
    listsPublish($front, $this->admin);

    expect(app(Sitemaps::class)->index())->not->toContain('http://localhost/sitemap-home.xml')
        ->and(app(Sitemaps::class)->page('home'))->toBeNull();
});

it('dates each item by the later of when it went live and when its published revision was written', function (): void {
    $updated = pageArticle($this->author, 'Harbour news');
    $dated = pageArticle($this->author, 'Older news');
    DB::table('contents')->where('id', $updated->id)->update(['published_at' => '2020-03-01 09:00:00+00']);
    DB::table('content_revisions')->where('id', $updated->published_revision_id)->update(['created_at' => '2020-05-02 10:30:00+00']);
    DB::table('contents')->where('id', $dated->id)->update(['published_at' => '2020-06-01 08:00:00+00']);
    DB::table('content_revisions')->where('id', $dated->published_revision_id)->update(['created_at' => '2020-01-01 00:00:00+00']);

    $dates = array_map(fn (SitemapAddress $address): ?string => $address->lastmod?->utc()->format(DATE_W3C), app(Sitemaps::class)->page('items-post')?->addresses ?? []);

    expect($dates)->toBe(['2020-05-02T10:30:00+00:00', '2020-06-01T08:00:00+00:00']);
});

it('names each item\'s images in its published version, the featured image and the body\'s, each once, at the widest width made, and none in the trash (I-296)', function (): void {
    $lead = pageImage($this->author, 1600, 900);
    $inline = pageImage($this->author, 800, 600);
    $gone = pageImage($this->author, 640, 480);
    pagePublish($this->author, 'Harbour news', [pageImageNode($inline, (string) Str::ulid()), pageImageNode($lead, (string) Str::ulid()), pageImageNode($gone, (string) Str::ulid())], [], [], $lead);
    DB::table('media')->where('id', $gone->id)->update(['deleted_at' => now()]);
    $widest = function ($media): string {
        $widths = array_filter($media->refresh()->variants, fn (mixed $variant, string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_BOTH);
        usort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return url(MediaDisks::public()->url($widths[0]['path']));
    };

    expect(app(Sitemaps::class)->page('items-post')?->addresses[0]->images)->toEqualCanonicalizing([$widest($lead), $widest($inline)]);
});

it('adds the children of a provider another part registers, after core\'s (I-306)', function (): void {
    pageArticle($this->author, 'Harbour news');
    app()->instance('test.sitemap.news', new class implements SitemapProvider
    {
        public function names(): array
        {
            return ['news'];
        }

        public function starts(string $name): array
        {
            return [];
        }

        public function page(string $name, ?string $from): SitemapPage
        {
            return new SitemapPage([new SitemapAddress('http://localhost/harbour-news/')]);
        }

        public function tags(string $name): array
        {
            return ['list:post'];
        }
    });
    app()->tag(['test.sitemap.news'], SitemapProvider::TAG);

    $index = app(Sitemaps::class)->index();

    expect(end($index))->toBe('http://localhost/sitemap-news.xml')
        ->and(($this->locs)(app(Sitemaps::class)->page('news')))->toBe(['http://localhost/harbour-news/']);
});

it('refuses two children with one name, naming it', function (): void {
    app()->instance('test.sitemap.twin', new class implements SitemapProvider
    {
        public function names(): array
        {
            return ['home'];
        }

        public function starts(string $name): array
        {
            return [];
        }

        public function page(string $name, ?string $from): SitemapPage
        {
            return new SitemapPage([]);
        }

        public function tags(string $name): array
        {
            return [];
        }
    });
    app()->tag(['test.sitemap.twin'], SitemapProvider::TAG);

    expect(fn () => app(Sitemaps::class)->index())->toThrow(LogicException::class, 'Two sitemaps have the name [home].');
});

it('finds a child\'s page starts afresh for each index the page cache stores, and without the page cache reuses them for ten minutes', function (): void {
    pageArticle($this->author, 'Harbour news');
    $at = fn (): int => (int) (Cache::get(Sitemaps::KEPT.'items-post')['at'] ?? 0);

    app(Sitemaps::class)->index();
    $found = $at();
    $this->travel(5)->minutes();
    app(Sitemaps::class)->index();

    expect($found)->toBeGreaterThan(0)->and($at())->toBe($found);

    $this->travel(6)->minutes();
    app(Sitemaps::class)->index();

    expect($at())->toBeGreaterThan($found);

    $folder = cacheOn();
    $again = $at();
    $this->travel(1)->minutes();
    app(Sitemaps::class)->index();
    cacheOff($folder);

    expect($at())->toBeGreaterThan($again);
});
