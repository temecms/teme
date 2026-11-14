<?php

declare(strict_types=1);

use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\Queries\NotFoundEntries;
use App\Domain\Seo\SearchAndSocial;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('lists the log the most asked for first, marks the addresses a redirect now answers, and says how many it keeps (I-871)', function (): void {
    rdLogged(['news/ferry-fares-2025' => 312, 'old-fares' => 4, 'category/port-harcourt-news' => 147], 'www.google.com');
    $exact = rdSaved('/old-fares', '/news/');

    $this->actingAs($this->editor)->get('/admin/tools/redirects/404')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/not-found')
            ->where('keep', 10000)
            ->where('counts', ['redirects' => 1, 'notFound' => 3])
            ->where('paged', false)
            ->where('next', null)
            ->has('rows', 3)
            ->where('rows.0.address', '/news/ferry-fares-2025')
            ->where('rows.0.count', 312)
            ->where('rows.0.referrerHost', 'www.google.com')
            ->where('rows.0.redirectId', null)
            ->where('rows.1.address', '/category/port-harcourt-news')
            ->where('rows.2.address', '/old-fares')
            ->where('rows.2.redirectId', $exact));
});

it('pages through the log a page at a time, forward from the most asked for', function (): void {
    lscrPrefer($this->editor, ['lists.per_page' => 25]);
    $counts = [];

    foreach (range(1, 26) as $n) {
        $counts[sprintf('gone-%02d', $n)] = 100 - $n;
    }

    rdLogged($counts);
    $page = fn (string $uri): array => $this->actingAs($this->editor)->get($uri)->assertOk()->viewData('page')['props'];

    $first = $page('/admin/tools/redirects/404');
    $second = $page('/admin/tools/redirects/404?count=75&path=gone-25');

    expect(count($first['rows']))->toBe(25)
        ->and($first['next'])->toBe(['count' => 75, 'path' => 'gone-25'])
        ->and(array_column($second['rows'], 'address'))->toBe(['/gone-26'])
        ->and($second['paged'])->toBeTrue()
        ->and($second['next'])->toBeNull();
});

it('keeps as many addresses as Search and social says, and links there for whoever may change it (I-874, I-970)', function (): void {
    cmSettings([NotFoundLogSection::SIZE => 2500], SearchAndSocial::KEY);
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($this->editor)->get('/admin/tools/redirects/404')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('keep', 2500)->where('settingsUrl', null));

    $this->actingAs($admin)->get('/admin/tools/redirects/404')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('settingsUrl', 'http://localhost/admin/settings/seo#section-not-found-log'));
});

it('reads a later page of the 404 log from its cursor in the index, not from the log\'s first row (I-298)', function (): void {
    DB::statement("INSERT INTO not_found_log (path, count, first_seen_at, last_seen_at) SELECT 'gone/' || g, 1 + g % 300, now(), now() - g * interval '1 second' FROM generate_series(1, 6000) g");
    DB::statement('ANALYZE not_found_log');
    // The plans a full log would price above this index are ruled out on a table this small.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');

    $plan = genericPlanOf(fn () => app(NotFoundEntries::class)->mostAsked(50, ['count' => 150, 'path' => 'gone/4950']), 'not_found_log');
    $rows = app(NotFoundEntries::class)->mostAsked(50, ['count' => 150, 'path' => 'gone/4950'])['rows'];

    expect($plan)->toContain('Index Scan using not_found_log_most_asked_index on not_found_log')
        ->toMatch('/Index Cond: \(count <= /')
        ->not->toContain('Seq Scan on not_found_log')
        ->and($rows[0]['count'])->toBeLessThanOrEqual(150)
        ->and(collect($rows)->every(fn (array $row): bool => $row['count'] < 150 || strcmp($row['path'], 'gone/4950') > 0))->toBeTrue();
});
