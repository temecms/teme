<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\Phases\PreparedObject;
use Illuminate\Support\Facades\Cache;

/**
 * What the people phase prepares for each person: it counts each release in the cache, so a test sees every one.
 */
final readonly class PreparedNote implements PreparedObject
{
    public function __construct(public string $id) {}

    public function release(): void
    {
        Cache::increment('fixture-released');
    }
}
