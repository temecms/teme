<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->filler = fn (int $number): string => 'f'.str_pad((string) $number, 25, '0', STR_PAD_LEFT);
});

it('reads the pages from an index of their own: a type\'s published items out of the trash, in ID order, with when each goes live (I-296)', function (): void {
    $index = (string) DB::table('pg_indexes')->where('tablename', 'contents')->where('indexname', 'contents_sitemap')->value('indexdef');

    expect($index)->toContain('(type, id) INCLUDE (published_at) WHERE')
        ->toContain("'published'")
        ->toContain('deleted_at IS NULL');
});

it('finds where each page after the first starts, one every 2,000 live items of the type in ID order, leaving out drafts, the trash, items dated ahead and other types', function (): void {
    planFillerPosts($this->author, 4500);
    lifecycleDraft($this->author, 'Still a draft');
    addrPublished($this->admin, 'About', 'page');
    DB::table('contents')->where('id', ($this->filler)(10))->update(['deleted_at' => now()]);
    DB::table('contents')->where('id', ($this->filler)(20))->update(['published_at' => now()->addDay()]);

    // 4,498 live: the 2,001st is filler 2,003, the 4,001st filler 4,003.
    expect(app(ListedItems::class)->starts('post'))->toBe([($this->filler)(2003), ($this->filler)(4003)]);
});

it('reads a page from its first key: of the next live items in ID order, those listed, each at its address with when it last changed', function (): void {
    $first = headPost($this->author, 'First story');
    $second = headPost($this->author, 'Second story');
    $third = headPost($this->author, 'Third story');

    $page = app(ListedItems::class)->page('post', $second->id);

    expect(array_column($page, 'id'))->toBe([$second->id, $third->id])
        ->and(array_column($page, 'path'))->toBe(['second-story', 'third-story'])
        ->and(array_column(app(ListedItems::class)->page('post', null), 'id'))->toBe([$first->id, $second->id, $third->id])
        ->and($page[0]['changed_at'])->not->toBe('');
});

it('lists an item exactly when its head lets search engines list it at its own address (I-292)', function (): void {
    $plain = headPost($this->author, 'Plain story');
    $hidden = headPost($this->author, 'Hidden story', [BuiltInFields::NOINDEX => true]);
    $named = headPost($this->author, 'Named story');
    $pointing = headPost($this->author, 'Pointing story', [BuiltInFields::CANONICAL => ['item' => $named->id]]);
    $leaving = headPost($this->author, 'Leaving story');
    $orphaned = headPost($this->author, 'Orphaned story', [BuiltInFields::CANONICAL => ['item' => $leaving->id]]);
    $elsewhere = headPost($this->author, 'Elsewhere story', [BuiltInFields::CANONICAL => ['address' => 'https://elsewhere.example/story']]);
    pageTrash($leaving, $this->admin);

    $listed = array_column(app(ListedItems::class)->page('post', null), 'id');

    foreach ([$plain, $hidden, $named, $pointing, $orphaned, $elsewhere] as $item) {
        $address = '/'.$item->slug.'/';
        $dom = headDom($address);
        $headLists = ! str_contains(headValue($dom, '//head/meta[@name="robots"]/@content'), 'noindex')
            && headValue($dom, '//head/link[@rel="canonical"]/@href') === 'http://localhost'.$address;

        expect(in_array($item->id, $listed, true))->toBe($headLists, $item->slug);
    }

    expect($listed)->toBe([$plain->id, $named->id, $orphaned->id]);
});

it('leaves the front page to the site\'s address, and lists the posts page at its own whatever its canonical says, as their heads do (I-292)', function (): void {
    $front = addrPublished($this->admin, 'Welcome', 'page');
    $latest = lifecycleDraft($this->admin, 'Latest', 'page');
    docSave($latest, $this->admin, docWith(docText()), [BuiltInFields::CANONICAL => ['address' => 'https://elsewhere.example/latest']]);
    listsPublish($latest, $this->admin);
    $about = addrPublished($this->admin, 'About', 'page');
    addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $front->id, 'postsPageId' => $latest->id]);

    expect(array_column(app(ListedItems::class)->page('page', null), 'id'))->toBe([$latest->id, $about->id])
        ->and(app(ListedItems::class)->listed($front->id))->not->toBeNull()
        ->and(headValue(headDom('/latest/'), '//head/link[@rel="canonical"]/@href'))->toBe('http://localhost/latest/')
        ->and(headValue(headDom('/'), '//head/link[@rel="canonical"]/@href'))->toBe('http://localhost/');
});

it('counts every page\'s start in contents_sitemap\'s own order, with no sort and no scan of the table (I-296)', function (): void {
    planFillerPosts($this->author, 2000);
    // Other types' rows between the posts, as a real table has, so walking the primary key
    // and filtering by type costs what it would at scale.
    DB::statement("INSERT INTO contents (id, type, slug, status, author_id, published_at, fields, title) SELECT left(md5(g::text), 26), (ARRAY['page', 'note'])[1 + g % 2], 'other-' || g, 'published', ?, now() - interval '1 day' - g * interval '1 second', '{}', 'Other ' || g FROM generate_series(1, 40000) g", [$this->author->id]);
    DB::statement('ANALYZE contents');
    // On a table this small the planner's choice among indexes is a coin toss, so the
    // plans a million rows would price above this index are ruled out for this test's
    // transaction; that the scan is index-only once a vacuum has run is the benchmark's
    // to show, since VACUUM can't run inside a test.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');

    $plan = genericPlanOf(fn () => app(ListedItems::class)->starts('post'), 'row_number()');

    expect($plan)->toMatch('/(Index Only Scan|Index Scan) using contents_sitemap on contents/')
        ->not->toContain('Seq Scan on contents')
        ->not->toContain('Sort Key');
});

it('reads page 500 as it reads page 1, a range of contents_sitemap from its first key, never an OFFSET (I-296)', function (): void {
    planFillerPosts($this->author, 2000);
    // Other types' rows between the posts, as a real table has, so the primary key's range is no cheaper than this index's.
    DB::statement("INSERT INTO contents (id, type, slug, status, author_id, published_at, fields, title) SELECT left(md5(g::text), 26), (ARRAY['page', 'note'])[1 + g % 2], 'other-' || g, 'published', ?, now() - interval '1 day' - g * interval '1 second', '{}', 'Other ' || g FROM generate_series(1, 40000) g", [$this->author->id]);
    DB::statement('ANALYZE contents');
    // As above: the plans a million rows would price above this index are ruled out.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    $read = fn () => app(ListedItems::class)->page('post', ($this->filler)(1000));

    $plan = genericPlanOf($read, 'JOIN paths address');

    expect($plan)->toContain('Index Scan using contents_sitemap on contents')
        ->toContain('id >= ')
        ->not->toContain('Seq Scan on contents')
        ->and(collect(planStatements($read))->pluck('query')->implode("\n"))->not->toContain('OFFSET');
});
