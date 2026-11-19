<?php

/**
 * A suggestion settled already: used, discarded or found out of date. Its
 * message is what the person reads.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

final class ActivityAlreadySettled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This suggestion was already used or put aside.');
    }
}
