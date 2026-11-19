<?php

/**
 * Teme's plugin and theme directory: in the installer, before Composer runs,
 * it fetches the directory's composer/packages.json, checks it against the
 * signed index's SHA-256, and writes it into the extensions folder, where
 * Composer reads it as a Composer repository; each archive it lists carries
 * the checksum Composer checks in turn.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use App\Domain\Extensions\Directory\DirectoryRefused;
use App\Domain\Extensions\Queries\DirectoryPackages;
use RuntimeException;

final readonly class DirectorySource implements SourceKind
{
    public const string KEY = 'directory';

    /** The folder, inside the extensions folder, that holds the checked packages.json. */
    public const string FOLDER = 'directory';

    public function __construct(private DirectoryPackages $packages) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [['type' => 'composer', 'url' => self::repositoryUrl($extensionsPath)]];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void
    {
        try {
            $index = $this->packages->usable();

            if ($request->package === null || $index->package($request->package, $request->kind) === null) {
                throw DirectoryRefused::notListed((string) $request->package);
            }

            $bytes = $this->packages->composerRepository();
        } catch (DirectoryRefused $refused) {
            throw new RuntimeException($refused->getMessage(), previous: $refused);
        }

        $folder = rtrim($extensionsPath, '/').'/'.self::FOLDER;

        if (! is_dir($folder) && ! mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException(__('The installer couldn’t write the directory’s package list.'));
        }

        $temporary = $folder.'/packages.json.'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || ! rename($temporary, $folder.'/packages.json')) {
            @unlink($temporary);

            throw new RuntimeException(__('The installer couldn’t write the directory’s package list.'));
        }
    }

    /**
     * The address Composer reads the checked packages.json from: a file in
     * this server's own extensions folder, never fetched by Composer itself.
     */
    public static function repositoryUrl(string $extensionsPath): string
    {
        return 'file://'.rtrim($extensionsPath, '/').'/'.self::FOLDER;
    }
}
