<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * The hash a release lists for each Dokploy compose file Teme has published, and the updater compares the
 * service's file against: SHA-256 of the text with Windows line endings made plain and one newline at the end.
 * scripts/release-notes.php hashes the same way, on its own.
 */
final class ComposeHash
{
    /** A hash as a release lists it. */
    public const string PATTERN = '/^[0-9a-f]{64}$/D';

    /**
     * Returns the file's hash.
     */
    public static function of(string $compose): string
    {
        return hash('sha256', rtrim(str_replace("\r\n", "\n", $compose))."\n");
    }
}
