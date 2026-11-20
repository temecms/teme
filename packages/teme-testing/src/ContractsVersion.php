<?php

/**
 * The newest version the checkout is at least: its teme/contracts' highest
 * `@since`, or Teme's newest release in CHANGELOG.md when that is newer (a
 * release that adds no contract). A test sets the site to it when
 * TEME_VERSION names no release.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final readonly class ContractsVersion
{
    /**
     * Returns the highest `@since` in packages/teme-contracts/src, or the
     * newest released version in the checkout's CHANGELOG.md if higher.
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

        $changelog = dirname(__DIR__, 3).'/CHANGELOG.md';

        if (is_file($changelog) && preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', (string) file_get_contents($changelog), $released) === 1
            && version_compare($released[1], $highest, '>')) {
            $highest = $released[1];
        }

        return $highest;
    }
}
