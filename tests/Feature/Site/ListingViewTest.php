<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Site\ListingViews;
use App\Models\User;
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
});

it('lists a category newest first, with numbered pages and a total', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    foreach (range(1, 25) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "Story {$i}", categories: [$news]);
    }

    $view = app(ListingViews::class)->forTerm($news->refresh(), 1);

    expect($view->items)->toHaveCount(10)
        ->and($view->items[0]->title)->toBe('Story 25')
        ->and($view->pagination->total)->toBe(3)
        ->and($view->subject->title)->toBe('News');
});

it('answers 404 past page 1000', function (): void {
    addrGet('/page/1001/')->assertNotFound();
});

it('reads each item\'s address through paths_one_current, never a scan of paths (1.6h)', function (): void {
    $post = pageArticle($this->author, 'Harbour lights');

    // Enough other addresses that reading them all costs more than one index
    // lookup, as it does on a real site.
    DB::statement("INSERT INTO paths (path, target_type, target_id, is_current) SELECT 'filler-' || g, 'content', 'X' || lpad(g::text, 25, '0'), true FROM generate_series(1, 2000) g");
    DB::statement('ANALYZE paths');

    // contents.id is char(26) and paths.target_id varchar(26): compared as
    // char, the cast falls on paths and its index can't answer the join.
    $plan = planOf(fn () => app(ListingViews::class)->items([(string) $post->id]), 'left join "paths"');

    // An index scan or a bitmap one: both read the index, which one the
    // planner picks varies with the load, and neither reads the whole table.
    expect($plan)->toMatch('/(Index Scan using|Bitmap Index Scan on) paths_one_current\b.*\n\s*Index Cond: .*\(target_id\)::text = \(contents\.id\)::text/')
        ->and($plan)->not->toContain('Seq Scan on paths');
});

it('reads an author\'s page straight from the published-order index, with no sort (1.6h)', function (): void {
    foreach (range(1, 3) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "Story {$i}");
    }
    // A busy author among many, whose posts it would otherwise sort, or
    // find among everyone's, for every page.
    planFillerPosts($this->author, 2000);
    planFillerPosts(User::factory()->create(), 20000);

    // A bitmap scan can't give the order, so the plan left shows whether the index can.
    DB::statement('SET LOCAL enable_bitmapscan = off');

    $plan = planOf(fn () => app(ListingViews::class)->forAuthor($this->author, 2), '"contents"."author_id" = ?');

    expect($plan)->toContain('using contents_author_published on contents')
        ->and($plan)->not->toContain('Filter')
        ->and($plan)->not->toContain('Sort');
});

it('counts a date archive only as far as its deepest page, never its whole range (1.6h)', function (): void {
    pageArticle($this->author, 'Story');
    planFillerPosts($this->author);
    $year = (int) now()->year;

    $view = app(ListingViews::class)->forDate($year, null, null, 1);
    $plan = planOf(fn () => app(ListingViews::class)->forDate($year, null, null, 1), 'count(*)');

    // A limit under the count: a year of a large site isn't counted to the end.
    expect($plan)->toMatch('/Aggregate.*\n\s*->\s+Limit/')
        ->and($view->pagination->total)->toBe((int) ceil(2001 / app(ListingViews::class)->perPage()));
});
