<?php

/**
 * What Composer actually installed (I-3353): each package's entry in
 * vendor/composer/installed.json, the image's then the extensions release's, the file Composer's autoloader and
 * Laravel's discovery read, which can differ from the package's own
 * composer.json (a private or `package` repository).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Extensions\ActiveRelease;
use Illuminate\Support\Env;

final class InstalledPackages
{
    /** @var array<string, array<string, mixed>>|null each package's entry, by name, read once */
    private ?array $entries = null;

    public function __construct(private readonly ?string $file = null) {}

    /**
     * Returns the installed package's autoload and extra, with its name, or
     * null when installed.json doesn't list it.
     *
     * @return array<string, mixed>|null
     */
    public function entry(string $name): ?array
    {
        $entry = $this->entries()[$name] ?? null;

        return $entry === null ? null : [
            'name' => $name,
            'type' => $entry['type'] ?? null,
            'autoload' => is_array($entry['autoload'] ?? null) ? $entry['autoload'] : [],
            'extra' => is_array($entry['extra'] ?? null) ? $entry['extra'] : [],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $files = $this->file !== null ? [$this->file] : [
            (Env::get('COMPOSER_VENDOR_DIR') ?: base_path('vendor')).'/composer/installed.json',
            // The extensions folder's release after the image's: the image's own packages always win.
            app(ActiveRelease::class)->installedJson(),
        ];
        $entries = [];

        foreach (array_filter($files) as $file) {
            $installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];

            foreach (is_array($packages) ? $packages : [] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && ! isset($entries[$package['name']])) {
                    /** @var array<string, mixed> $package */
                    $entries[$package['name']] = $package;
                }
            }
        }

        return $this->entries = $entries;
    }
}
