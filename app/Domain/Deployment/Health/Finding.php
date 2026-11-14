<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

/**
 * One thing a doctor's check found: how serious it is, what it is in one
 * sentence, and, when something is wrong, how to fix it in another.
 */
final readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $message,
        public ?string $fix = null,
    ) {}
}
