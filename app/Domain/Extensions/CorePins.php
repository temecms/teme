<?php

/**
 * The image's own packages, pinned at their exact versions, which the
 * extensions folder's composer.json replaces and provides, so Composer
 * resolves every plugin against Teme's real versions and never installs a
 * second copy of a package Teme has (docs/plans/plugin-installer.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions;

use JsonException;
use RuntimeException;

final readonly class CorePins
{
    /** Where `teme:extensions:pins` writes the pins at image build. */
    public const string CACHE = 'bootstrap/cache/teme-core-pins.php';

    /**
     * The pins for what Composer installed: every package and what each one
     * replaces at its version, what each provides, and a short hash that
     * names this set and nothing else.
     *
     * @param  array<array-key, mixed>  $installed  vendor/composer/installed.json, read
     * @param  list<string>  $leaveOut  package names not to pin (the dev packages)
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
     */
    public function build(array $installed, string $rootName, array $leaveOut = []): array
    {
        $replace = [$rootName => 'dev-main'];
        $provide = [];
        $packages = $installed['packages'] ?? $installed;
        $packages = is_array($packages) ? array_values(array_filter($packages, 'is_array')) : [];

        // By name, so two packages providing one name give the same pins in any order.
        usort($packages, fn (array $one, array $two): int => strcmp((string) ($one['name'] ?? ''), (string) ($two['name'] ?? '')));

        foreach ($packages as $package) {
            if (! is_string($package['name'] ?? null) || in_array($package['name'], $leaveOut, true)) {
                continue;
            }

            $version = (string) ($package['version'] ?? '');
            $replace[$package['name']] = $version;

            foreach (is_array($package['replace'] ?? null) ? $package['replace'] : [] as $name => $constraint) {
                $replace[(string) $name] ??= $constraint === 'self.version' ? $version : (string) $constraint;
            }

            foreach (is_array($package['provide'] ?? null) ? $package['provide'] : [] as $name => $constraint) {
                $provide[(string) $name] ??= $constraint === 'self.version' ? $version : (string) $constraint;
            }
        }

        ksort($replace);
        ksort($provide);

        return [
            'replace' => $replace,
            'provide' => $provide,
            'hash' => substr(hash('sha256', json_encode([$replace, $provide], JSON_THROW_ON_ERROR)), 0, 16),
        ];
    }

    /**
     * The pins for an installed.json file, its dev packages left out when
     * asked.
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
     */
    public function fromFile(string $installedJson, string $rootName, bool $withoutDev = false): array
    {
        try {
            $installed = is_file($installedJson) ? json_decode((string) file_get_contents($installedJson), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $installed = null;
        }

        if (! is_array($installed)) {
            throw new RuntimeException("Couldn't read Composer's list of installed packages at {$installedJson}.");
        }

        $dev = $withoutDev && is_array($installed['dev-package-names'] ?? null) ? array_values(array_map('strval', $installed['dev-package-names'])) : [];

        return $this->build($installed, $rootName, $dev);
    }

    /**
     * Writes the pins as a PHP file, a temporary file beside it renamed over
     * it, so a reader never sees half a file. Whether it was written.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     */
    public function write(array $pins, string $path): bool
    {
        $contents = '<?php return '.var_export($pins, true).';'.PHP_EOL;
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents) !== strlen($contents) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * The pins written at image build, or null when there are none.
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}|null
     */
    public function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $pins = require $path;

        return is_array($pins) && is_array($pins['replace'] ?? null) && is_array($pins['provide'] ?? null) && is_string($pins['hash'] ?? null) ? $pins : null;
    }

    /**
     * The pins this server runs with: the image's file, or, on a machine
     * built without it, read from vendor/ now, the dev packages left out.
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
     */
    public function current(): array
    {
        $file = getenv('TEME_CORE_PINS') ?: base_path(self::CACHE);
        $root = json_decode((string) @file_get_contents(base_path('composer.json')), true);

        return $this->read($file) ?? $this->fromFile(base_path('vendor/composer/installed.json'), is_array($root) && is_string($root['name'] ?? null) ? $root['name'] : 'laravel/laravel', true);
    }
}
