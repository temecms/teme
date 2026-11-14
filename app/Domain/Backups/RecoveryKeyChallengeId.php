<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A short name for one recovery key challenge (I-2401): the key page sends
 * it back with the typed groups, so groups typed from one key are never
 * checked against a newer key's challenge from another tab.
 */
final class RecoveryKeyChallengeId
{
    /**
     * The first 16 hexadecimal characters of the SHA-256 of the challenge's pending map as JSON.
     */
    public static function of(RecoveryKeyChallenge $challenge): string
    {
        return substr(hash('sha256', (string) json_encode($challenge->pending)), 0, 16);
    }
}
