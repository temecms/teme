<?php

declare(strict_types=1);

use App\Domain\Search\Snippet;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
});

it('finds published posts and pages by their body text', function (): void {
    $post = searchPublished($this->author, 'Harbour notes', 'The lifeboat crew launched at dawn.');
    $page = searchPublished($this->author, 'About', 'We cover the lifeboat station.', type: 'page');

    expect(searchIds('lifeboat'))->toEqualCanonicalizing([$post->id, $page->id]);
});

it('never finds drafts, trashed items or regions', function (): void {
    searchDraft($this->author, 'Draft', 'secret lifeboat plans');
    $trashed = searchPublished($this->author, 'Gone', 'an old lifeboat story');
    pageTrash($trashed, $this->author);
    searchRegion($this->author, 'a lifeboat appeal');

    expect(searchIds('lifeboat'))->toBe([]);
});

it('does not find a published item dated ahead until its time comes', function (): void {
    $ahead = searchPublished($this->author, 'Launch', 'the lifeboat launch is announced');
    $now = searchPublished($this->author, 'Harbour', 'the lifeboat is in the harbour');
    DB::table('contents')->where('id', $ahead->id)->update(['published_at' => now()->addDay()]);

    expect(searchIds('lifeboat'))->toBe([$now->id])
        ->and(app(SiteSearch::class)->search(new SearchRequest('lifeboat', 1, 10))->total)->toBe(1);

    $this->travel(2)->days();

    expect(searchIds('lifeboat'))->toEqualCanonicalizing([$ahead->id, $now->id]);
});

it('ranks the closer match first', function (): void {
    $once = searchPublished($this->author, 'Weather', 'Rain, and one lifeboat.');
    $often = searchPublished($this->author, 'Lifeboat day', 'The lifeboat, the lifeboat crew, and the lifeboat house.');

    expect(searchIds('lifeboat')[0])->toBe($often->id);
});

it('understands quotes and minus, as readers type them', function (): void {
    $a = searchPublished($this->author, 'A', 'the lifeboat crew trained');
    searchPublished($this->author, 'B', 'the crew of the lifeboat trained');

    expect(searchIds('"lifeboat crew"'))->toBe([$a->id])
        ->and(searchIds('lifeboat -trained'))->toBe([]);
});

it('runs no search for a query of only stop words', function (): void {
    searchPublished($this->author, 'A', 'the and of');
    DB::enableQueryLog();

    expect(searchIds('the and'))->toBe([])
        ->and(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'ts_rank_cd')))->toBeEmpty();
});

it('escapes the text before it marks the matches', function (): void {
    expect(Snippet::fromHeadline("a <script>x</script> \u{E000}lifeboat\u{E001} b"))
        ->toBe('a &lt;script&gt;x&lt;/script&gt; <mark>lifeboat</mark> b');
});

it('caps the total, and says so', function (): void {
    config(['search.total_cap' => 3]);
    foreach (range(1, 5) as $n) {
        searchPublished($this->author, "Post {$n}", 'lifeboat');
    }

    $hits = app(SiteSearch::class)->search(new SearchRequest('lifeboat', 1, 2));

    expect($hits->total)->toBe(3)->and($hits->totalCapped)->toBeTrue()->and($hits->hits)->toHaveCount(2);
});

it('ranks only the newest matches, up to the cap, however many items hold the word (I-458)', function (): void {
    config(['search.total_cap' => 3]);
    foreach (range(1, 6) as $n) {
        $this->travel(1)->minutes();
        searchPublished($this->author, "Post {$n}", 'the lifeboat launched');
    }

    $ranking = collect(planStatements(fn () => searchIds('lifeboat')))->first(fn (array $entry): bool => str_contains($entry['query'], 'ts_rank_cd'));
    $plan = json_decode((string) DB::select('EXPLAIN (ANALYZE, FORMAT JSON) '.$ranking['query'], $ranking['bindings'])[0]->{'QUERY PLAN'}, true);

    // The rows the rank is worked out for: the input of the sort by rank.
    $ranked = function (array $node) use (&$ranked): ?int {
        if (($node['Node Type'] ?? '') === 'Sort' && str_contains(implode(' ', $node['Sort Key'] ?? []), 'ts_rank_cd')) {
            return (int) $node['Plans'][0]['Actual Rows'];
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (($rows = $ranked($child)) !== null) {
                return $rows;
            }
        }

        return null;
    };

    expect($ranked($plan[0]['Plan']))->toBe(3);
});

it('ranks the newest matches among themselves: a closer match past the cap isn\'t shown (I-458)', function (): void {
    config(['search.total_cap' => 3]);
    $old = searchPublished($this->author, 'Lifeboat day', 'The lifeboat, the lifeboat crew, and the lifeboat house.');
    $newer = [];
    foreach (range(1, 3) as $n) {
        $this->travel(1)->minutes();
        $newer[] = searchPublished($this->author, "Post {$n}", 'Rain, and one lifeboat.')->id;
    }
    $this->travel(1)->minutes();
    $best = searchPublished($this->author, 'Lifeboat week', 'The lifeboat and the lifeboat crew.');

    expect(searchIds('lifeboat'))->toHaveCount(3)
        ->and(searchIds('lifeboat')[0])->toBe($best->id)
        ->and(searchIds('lifeboat'))->not->toContain($old->id);
});

it('finds a rare word through the full-text index, and walks a common one newest first (I-458)', function (): void {
    // The cap a small share of the common word's matches, as 1,000 of 70,000
    // are at a million articles, and the rare word in one item.
    config(['search.total_cap' => 10]);
    searchPublished($this->author, 'Cliffs', 'the kittiwake nests on the lifeboat house');
    planFillerPosts($this->author, 2000, 'the lifeboat crew trained');

    // The capped count and the ranking, which walk the matches the same way.
    $plans = fn (string $word): string => planOf(fn () => searchIds($word), 'SELECT count(*)')."\n".planOf(fn () => searchIds($word), 'ts_rank_cd');

    expect($plans('kittiwake'))->toContain('contents_search_tsv')
        ->and($plans('kittiwake'))->not->toContain('Seq Scan')
        ->and($plans('lifeboat'))->toContain('using contents_published_order on contents')
        ->and($plans('lifeboat'))->not->toContain('contents_search_tsv')
        ->and($plans('lifeboat'))->not->toContain('Seq Scan');
});
