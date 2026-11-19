<?php

/**
 * Laravel's package discovery, without plugins (I-3353): a package of type
 * teme-plugin reaches Teme only through its Plugin class, so Laravel never
 * registers the providers or aliases it lists under extra.laravel, enabled
 * or not, built afresh or read from a cache written before.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use Composer\InstalledVersions;
use Illuminate\Foundation\PackageManifest;

final class PackagesWithoutPlugins extends PackageManifest
{
    /**
     * Laravel's manifest, with every plugin left out.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getManifest()
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = parent::getManifest();

        // Composer's runtime data, already loaded: a request never reads installed.json.
        $plugins = array_map($this->format(...), InstalledVersions::getInstalledPackagesByType('teme-plugin'));

        return $this->manifest = array_diff_key($manifest, array_flip($plugins));
    }

    /**
     * What Laravel ignores when it builds the manifest (package:discover):
     * the root composer.json's dont-discover, and every plugin.
     *
     * @return list<string>
     */
    protected function packagesToIgnore()
    {
        return [...parent::packagesToIgnore(), ...$this->plugins()];
    }

    /**
     * The names of the installed packages of type teme-plugin, from the
     * installed.json Laravel's build reads anyway.
     *
     * @return list<string>
     */
    private function plugins(): array
    {
        $path = $this->vendorPath.'/composer/installed.json';
        $installed = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $names = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && ($package['type'] ?? null) === 'teme-plugin' && is_string($package['name'] ?? null)) {
                $names[] = $this->format($package['name']);
            }
        }

        return $names;
    }
}
