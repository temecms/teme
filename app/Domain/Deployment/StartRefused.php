<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use RuntimeException;

/**
 * Why a production site won't start (I-375): every problem StartRules
 * found, one per line, never a secret's value.
 */
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
