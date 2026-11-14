<?php

declare(strict_types=1);

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Queries\AiActivityFilters;
use App\Domain\Ai\Queries\AiMonthSummary;
use App\Domain\Ai\UsageLimits;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    aiFake();
    $this->person = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->filters = fn (): AiActivityFilters => app(AiActivityFilters::class);
    $this->summary = fn (?string $month = null): array => app(AiMonthSummary::class)->of($month);
});

it('offers the people who may ask AI, by name, and the one chosen if they no longer may', function (): void {
    User::factory()->create(['name' => 'Zed Author'])->assignRole('author');
    User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    User::factory()->create(['name' => 'Cy Contributor'])->assignRole('contributor');
    $former = User::factory()->create(['name' => 'Bea Former'])->assignRole('contributor');

    $labels = fn (?int $selected): array => array_column(($this->filters)()->people($selected), 'label');

    expect($labels(null))->toBe(['Ada Admin', 'Eze Editor', 'Zed Author'])
        ->and($labels($former->id))->toBe(['Ada Admin', 'Bea Former', 'Eze Editor', 'Zed Author'])
        ->and($labels($this->person->id))->toBe(['Ada Admin', 'Eze Editor', 'Zed Author']);
});

it('offers the registered tasks by label, and the one chosen if its task has gone', function (): void {
    aiTask('tidy');
    aiTask('describe');

    $options = fn (?string $selected): array => array_column(($this->filters)()->tasks($selected), 'label', 'value');

    // The stand-ins only: the real tasks registered at boot sit among them (I-1668).
    $own = fn (?string $selected): array => array_intersect_key($options($selected), ['describe' => true, 'old-task' => true, 'tidy' => true]);

    expect($own(null))->toBe(['describe' => 'Describe', 'tidy' => 'Tidy'])
        ->and($own('old-task'))->toBe(['describe' => 'Describe', 'old-task' => 'Old task', 'tidy' => 'Tidy']);
});

it('offers this month and each month the record still holds, newest first, and the one chosen (I-318)', function (): void {
    foreach (['2025-10-15 12:00', '2025-11-25 12:00', '2026-08-10 12:00'] as $when) {
        $this->travelTo(CarbonImmutable::parse($when, 'UTC'));
        actvRow($this->person);
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'));

    expect(array_column(($this->filters)()->months(null), 'label', 'value'))
        ->toBe(['2026-11' => 'November 2026', '2026-08' => 'August 2026', '2025-11' => 'November 2025'])
        ->and(array_keys(array_column(($this->filters)()->months('2025-01-01'), 'label', 'value')))->toBe(['2026-11', '2026-08', '2025-11', '2025-01']);
});

it('offers this month alone while the record is empty', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'));

    expect(array_column(($this->filters)()->months(null), 'value'))->toBe(['2026-11']);
});

it('shows this month\'s spend against the budget, and when it is reached (I-320)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'));
    actvRow($this->person, ['costMicrounits' => 12_400_000]);
    cmSettings([UsageLimits::BUDGET => 50], AiSettings::KEY);

    expect(($this->summary)())->toBe([
        'month' => '2026-11-01',
        'label' => 'November 2026',
        'current' => true,
        'costMicrounits' => 12_400_000,
        'requests' => 1,
        'unpricedRequests' => 0,
        'budgetMicrounits' => 50_000_000,
        'reached' => false,
    ]);

    actvRow($this->person, ['costMicrounits' => 37_600_000]);

    expect(($this->summary)()['reached'])->toBeTrue()
        ->and(($this->summary)('2026-11-01')['reached'])->toBeTrue();
});

it('shows a past month\'s spend without the budget', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
    actvRow($this->person, ['costMicrounits' => 3_000_000]);
    cmSettings([UsageLimits::BUDGET => 1], AiSettings::KEY);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'));

    expect(($this->summary)('2026-10-01'))->toBe([
        'month' => '2026-10-01',
        'label' => 'October 2026',
        'current' => false,
        'costMicrounits' => 3_000_000,
        'requests' => 1,
        'unpricedRequests' => 0,
        'budgetMicrounits' => null,
        'reached' => false,
    ]);
});

it('counts the requests that had no price and leaves their cost out', function (): void {
    actvRow($this->person, ['model' => 'fake-unpriced', 'costMicrounits' => null]);
    actvRow($this->person, ['costMicrounits' => 1_000]);

    $summary = ($this->summary)();

    expect($summary['costMicrounits'])->toBe(1_000)
        ->and($summary['requests'])->toBe(2)
        ->and($summary['unpricedRequests'])->toBe(1);
});

it('has no budget and never reaches one while none is set', function (): void {
    actvRow($this->person, ['costMicrounits' => 999_000_000]);

    expect(($this->summary)()['budgetMicrounits'])->toBeNull()
        ->and(($this->summary)()['reached'])->toBeFalse();
});

it('shows a month with no requests as nothing spent', function (): void {
    expect(($this->summary)('2026-03-01'))->toMatchArray(['costMicrounits' => 0, 'requests' => 0, 'unpricedRequests' => 0, 'label' => 'March 2026']);
});
