<?php

declare(strict_types=1);

use App\Domain\Benchmark\ScenarioChecks;

it('names a request that wrote, where a scenario promised to write nothing', function (): void {
    $scenario = ['writesNothing' => true];

    expect(ScenarioChecks::problems($scenario, 12.0, 9, 2, []))->toBe(['A request wrote to the database: 2 statements'])
        ->and(ScenarioChecks::problems($scenario, 12.0, 9, 0, []))->toBe([]);
});

it('names each index a scenario\'s plans were to read and didn\'t', function (): void {
    $scenario = ['reads' => ['contents_sitemap', 'paths_target_current']];

    expect(ScenarioChecks::problems($scenario, 40.0, 4, 0, ['contents_pkey', 'paths_target_current']))->toBe(['Read no contents_sitemap'])
        ->and(ScenarioChecks::problems($scenario, 40.0, 4, 0, ['paths_target_current', 'contents_sitemap']))->toBe([]);
});

it('keeps the hit\'s and the time limit\'s checks as they were', function (): void {
    $scenario = ['readsNothing' => true, 'within' => [['items' => 1000, 'seconds' => 10, 'what' => 'what an Empty press deletes in its request']]];

    expect(ScenarioChecks::problems($scenario, 12.5, 1, 1, []))->toBe([
        'A hit read the database: 2 statements',
        '1,000 items would take 12.5 s, over 10 s: what an Empty press deletes in its request',
    ]);
});
