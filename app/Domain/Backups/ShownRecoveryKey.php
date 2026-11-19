<?php

/**
 * A recovery key shown once, and the challenge that confirms it was kept
 * (I-2401).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

final readonly class ShownRecoveryKey
{
    public function __construct(public RecoveryKey $key, public RecoveryKeyChallenge $challenge) {}
}
