<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('runs hooks and invalidation at once when no outer transaction is open', function (): void {
    $log = [];
    schedProbe($log)->execute(schedProbeInput());

    expect($log)->toBe(['perform', 'hooks', 'cache']);
});

it('waits for the outermost commit when run inside another transaction', function (): void {
    $log = [];

    DB::transaction(function () use (&$log): void {
        schedProbe($log)->execute(schedProbeInput());
        $log[] = 'outer still open';
    });

    expect($log)->toBe(['perform', 'outer still open', 'hooks', 'cache']);
});

it('never runs them when the outer transaction rolls back', function (): void {
    $log = [];

    try {
        DB::transaction(function () use (&$log): void {
            schedProbe($log)->execute(schedProbeInput());

            throw new RuntimeException('outer fails');
        });
    } catch (RuntimeException) {
        //
    }

    expect($log)->toBe(['perform']);
});
