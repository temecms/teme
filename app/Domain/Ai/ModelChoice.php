<?php

/**
 * A task's model, and the fallback asked once when the first can't answer
 * (I-315).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

final readonly class ModelChoice
{
    public function __construct(public ModelRef $primary, public ?ModelRef $fallback = null) {}
}
