<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use App\Domain\Deployment\Health\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rows as the queue and the heartbeats leave them, at times a test picks:
 * a job waiting or held by a worker, and a heartbeat.
 */
final class HealthRows
{
    public static function job(string $queue, CarbonImmutable $availableAt, ?CarbonImmutable $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => $reservedAt === null ? 0 : 1,
            'reserved_at' => $reservedAt?->getTimestamp(),
            'available_at' => $availableAt->getTimestamp(),
            'created_at' => $availableAt->getTimestamp(),
        ]);
    }

    public static function beat(string $name, CarbonImmutable $at): void
    {
        DB::table('heartbeats')->upsert([['name' => $name, 'beaten_at' => Heartbeats::moment($at)]], ['name'], ['beaten_at']);
    }
}
