<?php

/**
 * The extensions folder's own composer.json, written only by the installer:
 * the packages people asked for, every package of the image pinned at its
 * version, the sources' repositories, and settings that let no package's
 * code run while Composer works (docs/plans/plugin-installer.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use JsonException;

final readonly class ProjectFile
{
    public const string NAME = 'teme/site-extensions';

    /**
     * The composer.json for a new release: what the current one requires,
     * with these pins and repositories.
     *
     * @param  array<string, mixed>|null  $current  the current release's composer.json, or null for the first
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     * @param  list<array<string, mixed>>  $repositories
     * @param  array<string, string>  $sources  each requested package's source kind
     *
     * @return array<string, mixed>
     */
    public function compose(?array $current, array $pins, array $repositories, bool $packagist, array $sources = []): array
    {
        $require = is_array($current['require'] ?? null) ? $current['require'] : [];
        $kept = is_array($current['repositories'] ?? null) ? array_values(array_filter($current['repositories'], fn (mixed $entry): bool => is_array($entry) && ! array_key_exists('packagist.org', $entry))) : [];
        $known = is_array($current['extra']['teme']['sources'] ?? null) ? $current['extra']['teme']['sources'] : [];

        $all = [];

        foreach ([...$kept, ...$repositories] as $entry) {
            $all[json_encode($entry, JSON_THROW_ON_ERROR)] = $entry;
        }

        $all = array_values($all);

        if (! $packagist) {
            $all[] = ['packagist.org' => false];
        }

        ksort($require);
        $sourceList = [...$known, ...$sources];
        ksort($sourceList);

        return [
            'name' => self::NAME,
            'description' => 'Plugins and themes installed from Teme\'s admin. Written by the installer; never edit it by hand.',
            'type' => 'project',
            'require' => $require === [] ? (object) [] : $require,
            'replace' => $pins['replace'],
            'provide' => $pins['provide'] === [] ? (object) [] : $pins['provide'],
            'repositories' => $all,
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
            'config' => [
                'allow-plugins' => false,
                'prepend-autoloader' => false,
                'optimize-autoloader' => true,
                'secure-http' => true,
                'sort-packages' => true,
                'lock' => true,
            ],
            'extra' => ['teme' => ['core' => $pins['hash'], 'sources' => $sourceList === [] ? (object) [] : $sourceList]],
        ];
    }

    /**
     * Reads a release's composer.json, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $release): ?array
    {
        $file = $release.'/composer.json';

        try {
            $json = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * Writes a release's composer.json.
     *
     * @param  array<string, mixed>  $project
     */
    public function write(string $release, array $project): void
    {
        $json = json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (@file_put_contents($release.'/composer.json', $json) !== strlen($json)) {
            throw new InstallerFailed('Couldn\'t write the new release\'s composer.json.');
        }
    }
}
