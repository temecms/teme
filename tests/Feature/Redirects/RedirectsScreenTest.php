<?php

declare(strict_types=1);

use App\Domain\Redirects\Queries\RedirectList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->props = fn (string $uri = '/admin/tools/redirects'): array => $this->actingAs($this->editor)->get($uri)->assertOk()->viewData('page')['props'];
});

it('lists every redirect newest first, where it sends readers in words, and what each does now (I-694, I-857, I-858)', function (): void {
    $fares = addrPublished($this->editor, 'Ferry fares');
    $market = lifecycleDraft($this->editor, 'Market day');
    addrPublished($this->editor, 'About us');
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');

    $ids = [
        'fares' => rdSaved('/old-fares', item: $fares->id),
        'market' => rdSaved('/market', item: $market->id),
        'about' => rdSaved('/about-us', '/news/'),
        'podcast' => rdSaved('/podcast', 'https://podcasts.example.net/ledger', 302),
        'timetable' => rdSaved('/timetable', file: $file->id),
        'withdrawn' => rdSaved('/withdrawn', status: 410, on: false),
        'pattern' => rdSaved('/2019/[slug]', '/[slug]/'),
    ];
    DB::table('redirects')->where('id', $ids['fares'])->update(['hits' => 12, 'last_hit_at' => '2026-10-08 08:30:00+00', 'note' => 'From the print edition', 'origin' => 'import']);

    $rows = collect(($this->props)()['rows'])->keyBy('id');

    expect($rows->keys()->all())->toBe(array_reverse(array_values($ids)))
        ->and($rows[$ids['fares']])->toBe([
            'id' => $ids['fares'],
            'from' => '/old-fares',
            'pattern' => false,
            'status' => 301,
            'statusLabel' => 'Moved permanently',
            'to' => ['kind' => 'content', 'address' => '/ferry-fares/', 'title' => 'Ferry fares', 'contentId' => $fares->id, 'mediaId' => null],
            'note' => 'From the print edition',
            'origin' => 'import',
            'hits' => 12,
            'lastHitAt' => '2026-10-08T08:30:00+00:00',
            'on' => true,
            'state' => 'on',
            'stateLabel' => 'On',
        ])
        ->and($rows[$ids['market']]['to']['title'])->toBe('Market day')
        ->and($rows[$ids['market']]['to']['address'])->toBeNull()
        ->and($rows[$ids['market']]['stateLabel'])->toBe('Waiting: the post isn’t published')
        ->and($rows[$ids['about']]['to'])->toBe(['kind' => 'address', 'address' => '/news/', 'title' => null, 'contentId' => null, 'mediaId' => null])
        ->and($rows[$ids['about']]['stateLabel'])->toBe('Waiting: a page answers this address')
        ->and($rows[$ids['podcast']]['to'])->toBe(['kind' => 'outside', 'address' => 'https://podcasts.example.net/ledger', 'title' => null, 'contentId' => null, 'mediaId' => null])
        ->and($rows[$ids['podcast']]['statusLabel'])->toBe('Found (temporary)')
        ->and($rows[$ids['timetable']]['to']['kind'])->toBe('media')
        ->and($rows[$ids['timetable']]['to']['title'])->toBe($file->name)
        ->and($rows[$ids['timetable']]['to']['mediaId'])->toBe($file->id)
        ->and($rows[$ids['timetable']]['to']['address'])->not->toBeNull()
        ->and($rows[$ids['withdrawn']]['to'])->toBe(['kind' => null, 'address' => null, 'title' => null, 'contentId' => null, 'mediaId' => null])
        ->and($rows[$ids['withdrawn']]['statusLabel'])->toBe('Gone')
        ->and($rows[$ids['withdrawn']]['stateLabel'])->toBe('Off')
        ->and($rows[$ids['withdrawn']]['lastHitAt'])->toBeNull()
        ->and($rows[$ids['pattern']]['pattern'])->toBeTrue()
        ->and($rows[$ids['pattern']]['from'])->toBe('/2019/[slug]');
});

it('finds a redirect by its address, its typed target or its note, a trailing slash ignored and every wildcard taken as written', function (string $search, array $found): void {
    rdSaved('/old-fares', '/news/');
    rdSaved('/podcast', 'https://podcasts.example.net/ledger');
    DB::table('redirects')->where('source', 'podcast')->update(['note' => 'Our 100% local show']);
    rdSaved('/obituaries_2019', '/obituaries/');

    $props = ($this->props)('/admin/tools/redirects?search='.rawurlencode($search));

    expect(array_column($props['rows'], 'from'))->toEqualCanonicalizing($found)
        ->and($props['search'])->toBe($search);
})->with([
    'the address as the site writes it' => ['/Old-Fares/', ['/old-fares']],
    'a typed target' => ['podcasts.example', ['/podcast']],
    'a note' => ['local show', ['/podcast']],
    'a percent sign' => ['%', ['/podcast']],
    'an underscore' => ['_', ['/obituaries_2019']],
]);

it('shows a page at a time, the person\'s own number of rows, newer and older by keyset', function (): void {
    lscrPrefer($this->editor, ['lists.per_page' => 25]);
    $ids = [];

    foreach (range(1, 27) as $n) {
        $ids[] = rdSaved("/old-{$n}", '/news/');
    }

    $first = ($this->props)();
    $second = ($this->props)('/admin/tools/redirects?after='.$first['older']);
    $back = ($this->props)('/admin/tools/redirects?before='.$second['newer']);

    expect(array_column($first['rows'], 'id'))->toBe(array_slice(array_reverse($ids), 0, 25))
        ->and($first['newer'])->toBeNull()
        ->and($first['older'])->toBe($ids[2])
        ->and(array_column($second['rows'], 'id'))->toBe([$ids[1], $ids[0]])
        ->and($second['newer'])->toBe($ids[1])
        ->and($second['older'])->toBeNull()
        ->and(array_column($back['rows'], 'id'))->toBe(array_slice(array_reverse($ids), 0, 25))
        ->and($back['newer'])->toBeNull()
        ->and($back['older'])->toBe($ids[2]);
});

it('opens the form with the address it is given, says how many redirects and logged addresses there are, and passes an import\'s report on', function (): void {
    rdSaved('/old-fares', '/news/');
    rdLogged(['never-was' => 3]);
    $report = ['added' => 1, 'refused' => [['line' => 3, 'reason' => 'Line 3: 404 isn’t a status a redirect can have.']]];

    $this->actingAs($this->editor)->withSession(['redirects.imported' => $report])
        ->get('/admin/tools/redirects?from=%2Fharbour-news%2F')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/redirects')
            ->where('prefill', ['from' => '/harbour-news/'])
            ->where('counts', ['redirects' => 1, 'notFound' => 1])
            ->where('imported', $report)
            ->where('canManageAddresses', false)
            ->where('search', '')
            ->where('siteTimezone', 'UTC'));
});

it('refuses the list and the log to an author or a contributor', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools/redirects')->assertForbidden();
    $this->actingAs($person)->get('/admin/tools/redirects/404')->assertForbidden();
})->with(['author', 'contributor']);

it('can find a word in 100,000 redirects through their trigram indexes, never reading every one (I-297)', function (): void {
    DB::statement("INSERT INTO redirects (id, source, kind, status, target_kind, target, target_path, origin, note, created_at, updated_at) SELECT lpad(g::text, 26, '0'), 'archive/story-' || g, 'exact', 301, 'address', '/story-' || g || '/', 'story-' || g, 'import', '', now(), now() FROM generate_series(1, 3000) g");
    DB::statement('ANALYZE redirects');
    // The plans that read every row are ruled out, so the plan left shows the indexes can serve the search.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_indexscan = off');

    $plan = genericPlanOf(fn () => app(RedirectList::class)->page('story-2718', 50), 'ILIKE');

    expect($plan)->toContain('redirects_source_trgm')
        ->toContain('redirects_target_trgm')
        ->toContain('redirects_note_trgm')
        ->not->toContain('Seq Scan on redirects')
        ->and(collect(app(RedirectList::class)->page('story-2718', 50)['rows'])->pluck('id')->all())->toBe([str_pad('2718', 26, '0', STR_PAD_LEFT)]);
});

it('can find the address of each item a redirect sends readers to through the paths index, never reading every address (I-872)', function (): void {
    $fares = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/old-fares', item: $fares->id);
    DB::statement("INSERT INTO paths (path, target_type, target_id, is_current, created_at) SELECT 'filler-' || g, 'term', lpad(g::text, 26, '0'), true, now() FROM generate_series(1, 3000) g");
    DB::statement('ANALYZE paths');
    DB::statement('ANALYZE contents');
    DB::statement('ANALYZE redirects');
    // Every table the test filled is analyzed, and the plans that read every address are ruled out, so the plan left shows the index can serve the join, by an index scan or a bitmap one.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_hashjoin = off');
    DB::statement('SET LOCAL enable_mergejoin = off');

    $plan = genericPlanOf(fn () => app(RedirectList::class)->page('', 50), '"paths" as "p"');

    expect(planReadsIndex($plan, 'paths_\w+', 'paths p'))->toBeTrue($plan)
        ->and($plan)->toMatch('/Index Cond: .*\(target_id\)::text = \(c\.id\)::text/')
        ->not->toContain('Seq Scan on paths')
        ->and(app(RedirectList::class)->page('', 50)['rows'][0]['to']['address'])->toBe('/ferry-fares/');
});

it('reads only the addresses of the redirects it shows when it says what answers them, through the paths index (I-858)', function (): void {
    $fares = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/gone-for-good', '/news/');
    // Old addresses of other items, which a redirect on the screen never names.
    DB::statement("INSERT INTO paths (path, target_type, target_id, is_current, created_at) SELECT 'old-' || g, 'content', ?, false, now() FROM generate_series(1, 3000) g", [$fares->id]);
    DB::statement('ANALYZE paths');
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = genericPlanOf(fn () => app(RedirectList::class)->page('', 50), '"paths" as "p" where "p"."path" in');

    expect($plan)->toContain('paths_path_unique')
        ->not->toContain('Seq Scan on paths')
        ->and(collect(app(RedirectList::class)->page('', 50)['rows'])->pluck('state')->all())->toBe(['on']);
});
