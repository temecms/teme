<?php

/**
 * Why a production site won't start (I-375): every problem StartRules
 * found, one per line, never a secret's value.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use RuntimeException;

final class StartRefused extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(array $problems): self
    {
        return new self("Teme cannot start in production:\n- ".implode("\n- ", $problems));
    }
}
