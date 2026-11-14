<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A recovery key shown once, and the challenge that confirms it was kept
 * (I-2401).
 */
final readonly class ShownRecoveryKey
{
    public function __construct(public RecoveryKey $key, public RecoveryKeyChallenge $challenge) {}
}
