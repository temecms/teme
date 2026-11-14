<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

/**
 * A gate's answer when something may not go ahead: the plugin that refused
 * it, and one sentence saying why (I-3353).
 */
final readonly class Refusal
{
    public function __construct(
        public string $owner,
        public string $reason,
    ) {}
}
