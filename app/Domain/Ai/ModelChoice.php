<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * A task's model, and the fallback asked once when the first can't answer
 * (I-315).
 */
final readonly class ModelChoice
{
    public function __construct(public ModelRef $primary, public ?ModelRef $fallback = null) {}
}
