<?php

/**
 * A gate listener's refusal, with one sentence saying why.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A gate listener's refusal, with one sentence saying why.
 *
 * @since 0.5.0
 */
final readonly class Veto
{
    /**
     * @param  string  $reason  one sentence for the person refused
     *
     * @since 0.5.0
     */
    public function __construct(public string $reason) {}
}
