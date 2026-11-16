<?php

declare(strict_types=1);

namespace Teme\Testing;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The newest version the checkout's teme/contracts offers: its highest
 * `@since`. A checkout that offers a contract since 0.9.0 is at least 0.9.0,
 * so a test sets the site to it when TEME_VERSION names no release.
 */
final readonly class ContractsVersion
{
    /**
     * Returns the highest `@since` in packages/teme-contracts/src.
     */
    public static function highest(): string
    {
        $highest = '0.1.0';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/teme-contracts/src', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            preg_match_all('/@since\s+(\d+\.\d+\.\d+)/', (string) file_get_contents($file->getPathname()), $found);

            foreach ($found[1] as $version) {
                if (version_compare($version, $highest, '>')) {
                    $highest = $version;
                }
            }
        }

        return $highest;
    }
}
