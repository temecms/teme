<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use RuntimeException;

/**
 * The preflight's refusal: every problem found, in words, before anything is written (I-363).
 */
final class RestoreRefused extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
