<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * A suggestion settled already: used, discarded or found out of date. Its
 * message is what the person reads.
 */
final class ActivityAlreadySettled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This suggestion was already used or put aside.');
    }
}
