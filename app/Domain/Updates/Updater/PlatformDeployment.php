<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Carbon\CarbonImmutable;

/**
 * One deployment as the platform reports it: its ID, running, done, error or cancelled, and when it was made and ended.
 */
final readonly class PlatformDeployment
{
    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string ERROR = 'error';

    public const string CANCELLED = 'cancelled';

    public function __construct(
        public string $id,
        public string $state,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $finishedAt,
    ) {}
}
