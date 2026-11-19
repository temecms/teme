<?php

/**
 * Why a backup run stopped, in words people read, with what it saw at each
 * place it reached for; the run records both as it ends (I-362).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

final class BackupRefused extends RuntimeException
{
    /**
     * @param  array<string, PlaceOutcome>  $places  place ID => what the run saw there
     */
    public function __construct(string $sentence, public readonly array $places = [])
    {
        parent::__construct($sentence);
    }
}
