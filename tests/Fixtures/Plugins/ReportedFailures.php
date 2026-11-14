<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * An exception handler that keeps each failure reported to it, with the
 * log context it was reported in, instead of writing to the log.
 */
final class ReportedFailures extends Handler
{
    /** @var list<array{0: Throwable, 1: array<string, mixed>}> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = [$e, Context::all()];
    }
}
