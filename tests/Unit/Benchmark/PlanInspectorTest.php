<?php

declare(strict_types=1);

use App\Domain\Benchmark\PlanInspector;

function benchPlan(array $node): array
{
    return [['Plan' => $node]];
}

$sizes = ['contents' => 1_000_000, 'users' => 200];

it('flags a sequential scan on a large table', function () use ($sizes): void {
    $plan = benchPlan(['Node Type' => 'Limit', 'Plans' => [['Node Type' => 'Seq Scan', 'Relation Name' => 'contents', 'Plan Rows' => 1000000]]]);

    expect((new PlanInspector)->problems($plan, $sizes, false))->toBe(['Sequential scan on contents (1,000,000 rows)']);
});

it('allows a sequential scan on a small table', function () use ($sizes): void {
    $plan = benchPlan(['Node Type' => 'Seq Scan', 'Relation Name' => 'users', 'Plan Rows' => 200]);

    expect((new PlanInspector)->problems($plan, $sizes, false))->toBe([]);
});

it('flags a large sort only where index order is expected', function () use ($sizes): void {
    $plan = benchPlan(['Node Type' => 'Limit', 'Plans' => [['Node Type' => 'Sort', 'Plan Rows' => 5000, 'Plans' => [['Node Type' => 'Index Scan', 'Relation Name' => 'contents', 'Plan Rows' => 5000]]]]]);

    expect((new PlanInspector)->problems($plan, $sizes, true))->toBe(['Sort of 5,000 rows where the index should give the order'])
        ->and((new PlanInspector)->problems($plan, $sizes, false))->toBe([]);
});

it('does not judge a sort in a statement that does not page', function () use ($sizes): void {
    $plan = benchPlan(['Node Type' => 'Sort', 'Plan Rows' => 282, 'Plans' => [['Node Type' => 'Index Scan', 'Relation Name' => 'content_terms', 'Plan Rows' => 282]]]);

    expect((new PlanInspector)->problems($plan, $sizes, true))->toBe([]);
});
