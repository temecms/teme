<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');

    // A post published in September; a draft each in October, the editor's later.
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'));
    $this->harbour = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($this->harbour, $this->editor);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    $this->market = lifecycleDraft($this->author, 'Market day');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
    $this->ferry = lifecycleDraft($this->editor, 'Ferry fares');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $this->page = fn (array $query = [], ?User $viewer = null): array => $this->actingAs($viewer ?? $this->editor)
        ->get(route('content.index', ['type' => 'posts', ...$query]))
        ->assertOk()
        ->inertiaProps();
    $this->titles = fn (array $page): array => array_column($page['rows'], 'title');
});

it('lists the months that hold the tab\'s items, newest first, each with its count (I-3131)', function (): void {
    expect(($this->page)()['dates'])->toBe([
        'by' => 'updated',
        'months' => [['month' => '2026-10', 'count' => 2], ['month' => '2026-09', 'count' => 1]],
    ]);
});

it('counts the Published tab by date published, and the other tabs by last change', function (): void {
    expect(($this->page)(['tab' => 'published'])['dates'])->toBe(['by' => 'published', 'months' => [['month' => '2026-09', 'count' => 1]]])
        ->and(($this->page)(['tab' => 'drafts'])['dates'])->toBe(['by' => 'updated', 'months' => [['month' => '2026-10', 'count' => 2]]])
        ->and(($this->page)(['tab' => 'trash'])['dates'])->toBe(['by' => 'updated', 'months' => []]);
});

it('counts Mine from the viewer\'s own items', function (): void {
    expect(($this->page)(['tab' => 'mine'], $this->author)['dates']['months'])->toBe([['month' => '2026-10', 'count' => 1]]);
});

it('lists the months without counts while another filter narrows the list, and keeps them under the Date alone (I-140)', function (): void {
    expect(($this->page)(['search' => 'harbour'])['dates']['months'])->toBe([['month' => '2026-10', 'count' => null], ['month' => '2026-09', 'count' => null]])
        ->and(($this->page)(['author' => (string) $this->author->id])['dates']['months'][0]['count'])->toBeNull()
        ->and(($this->page)(['month' => '2026-10'])['dates']['months'][0])->toBe(['month' => '2026-10', 'count' => 2]);
});

it('filters by a range of the site\'s days, To included, and from a day on', function (): void {
    lscrTimezone('Africa/Lagos'); // UTC+1 all year
    // 23:30 UTC on 30 September is 00:30 on 1 October in Lagos; 22:30 is still 30 September.
    DB::table('contents')->where('id', $this->harbour->id)->update(['updated_at' => '2026-09-30 23:30:00+00']);
    DB::table('contents')->where('id', $this->market->id)->update(['updated_at' => '2026-09-30 22:30:00+00']);

    $september = ($this->page)(['from' => '2026-09-01', 'to' => '2026-09-30']);
    $since = ($this->page)(['from' => '2026-10-01']);

    expect(($this->titles)($september))->toBe(['Market day'])
        ->and($september['filtered'])->toBeTrue()
        ->and($september['filters'])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and(($this->titles)($since))->toBe(['Ferry fares', 'Harbour news'])
        ->and($since['filters'])->not->toHaveKey('to');
});

it('ends a range at the next day\'s own midnight, even when the zone skipped the day\'s', function (): void {
    lscrTimezone('America/Santiago'); // its clocks go from 00:00 to 01:00 on Sunday 6 September 2026
    // 03:30 UTC on 7 September is 00:30 on the 7th in Santiago: the day after To.
    DB::table('contents')->where('id', $this->market->id)->update(['updated_at' => '2026-09-07 03:30:00+00']);
    // 04:30 UTC on 6 September is 01:30 on the 6th, the first hour that day has.
    DB::table('contents')->where('id', $this->harbour->id)->update(['updated_at' => '2026-09-06 04:30:00+00']);
    // 02:00 UTC on 6 September is 22:00 on the 5th in Santiago (UTC-4 until the change): the day before From.
    DB::table('contents')->where('id', $this->ferry->id)->update(['updated_at' => '2026-09-06 02:00:00+00']);

    expect(($this->titles)(($this->page)(['from' => '2026-09-06', 'to' => '2026-09-06'])))->toBe(['Harbour news']);
});

it('takes the month over From and To, which then aren\'t kept', function (): void {
    $page = ($this->page)(['month' => '2026-10', 'from' => '2026-09-01', 'to' => '2026-09-30']);

    expect(($this->titles)($page))->toBe(['Ferry fares', 'Market day'])
        ->and($page['filters'])->toHaveKey('month')
        ->and($page['filters'])->not->toHaveKeys(['from', 'to']);
});

it('ignores a From or To that isn\'t a day, and a range that ends before it starts', function (array $query): void {
    $page = ($this->page)($query);

    expect($page['rows'])->toHaveCount(3)
        ->and($page['filtered'])->toBeFalse()
        ->and($page['filters'])->not->toHaveKeys(['from', 'to']);
})->with([
    'a day without its zeros' => [['from' => '2026-9-1']],
    'a word' => [['to' => 'yesterday']],
    'a day that never was' => [['from' => '2026-02-30']],
    'a time' => [['from' => '2026-10-01 00:00']],
    'To before From' => [['from' => '2026-10-02', 'to' => '2026-10-01']],
]);

it('reads the months in one query, whatever the tab', function (string $tab): void {
    ($this->page)(['tab' => $tab]); // warms the permission cache
    DB::flushQueryLog();
    DB::enableQueryLog();
    ($this->page)(['tab' => $tab]);

    $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'content_counts') && str_contains($query['query'], 'right(bucket, 7)'));

    expect($reads)->toHaveCount(1);
})->with(['all', 'mine', 'published', 'trash']);
