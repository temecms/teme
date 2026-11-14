<?php

declare(strict_types=1);

// Plans as PostgreSQL writes them, cut to the lines that matter.

it('takes an index scan, an index-only scan or a bitmap scan of the index as reading the relation through it', function (string $plan): void {
    expect(planReadsIndex($plan, 'paths_\w+', 'paths p'))->toBeTrue();
})->with([
    'an index scan' => ["Nested Loop\n  ->  Seq Scan on redirects r  (cost=0.00..1.01 rows=1 width=80)\n  ->  Index Scan using paths_one_current on paths p  (cost=0.28..8.30 rows=1 width=40)\n        Index Cond: ((target_id)::text = (c.id)::text)"],
    'an index-only scan' => ['  ->  Index Only Scan using paths_target_index on paths p  (cost=0.28..4.30 rows=1 width=40)'],
    'a backward index scan' => ['  ->  Index Scan Backward using paths_one_current on paths p  (cost=0.28..8.30 rows=1 width=40)'],
    'a bitmap scan' => ["  ->  Bitmap Heap Scan on paths p  (cost=4.29..8.31 rows=1 width=40)\n        Recheck Cond: ((target_id)::text = (c.id)::text)\n        ->  Bitmap Index Scan on paths_one_current  (cost=0.00..4.29 rows=1 width=0)\n              Index Cond: ((target_id)::text = (c.id)::text)"],
]);

it('refuses a plan that reads the relation another way, or reads another relation', function (string $plan): void {
    expect(planReadsIndex($plan, 'paths_\w+', 'paths p'))->toBeFalse();
})->with([
    'a sequential scan' => ['  ->  Seq Scan on paths p  (cost=0.00..55.00 rows=3000 width=40)'],
    'a bitmap scan of an index the pattern doesn’t name' => ["  ->  Bitmap Heap Scan on paths p  (cost=4.29..8.31 rows=1 width=40)\n        ->  Bitmap Index Scan on contents_pkey  (cost=0.00..4.29 rows=1 width=0)"],
    'an index scan of another alias' => ['  ->  Index Scan using paths_one_current on paths q  (cost=0.28..8.30 rows=1 width=40)'],
    'an index scan of an index the pattern doesn’t name' => ['  ->  Index Scan using contents_pkey on paths p  (cost=0.28..8.30 rows=1 width=40)'],
]);
