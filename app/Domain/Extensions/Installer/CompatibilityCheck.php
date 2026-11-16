<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use Composer\Semver\VersionParser;
use Throwable;

/**
 * Whether a release built for one image still fits another, read from its
 * lock without running Composer: every requirement on a package the image
 * has is met by the image's version, PHP and its extensions are there, and
 * no locked package is now one the image ships itself.
 */
final readonly class CompatibilityCheck
{
    /**
     * Every reason the release no longer fits; empty when it does.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     *
     * @return list<string>
     */
    public function problems(string $release, array $pins): array
    {
        return array_column($this->findings($release, $pins), 1);
    }

    /**
     * The locked packages that no longer fit, which a rebuild lets move.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     *
     * @return list<string>
     */
    public function blocking(string $release, array $pins): array
    {
        return array_values(array_unique(array_column($this->findings($release, $pins), 0)));
    }

    /**
     * Each locked package that no longer fits, with why.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     *
     * @return list<array{0: string, 1: string}>
     */
    private function findings(string $release, array $pins): array
    {
        $problems = [];
        $parser = new VersionParser;

        foreach (StageValidator::locked($release) as $package) {
            $name = $package['name'];

            if (isset($pins['replace'][$name])) {
                $problems[] = [$name, "{$name} is now part of Teme itself."];
            }

            foreach (is_array($package['require'] ?? null) ? $package['require'] : [] as $required => $constraint) {
                $required = strtolower((string) $required);
                $constraint = (string) $constraint;

                if ($required === 'php') {
                    if (! self::matches($parser, PHP_VERSION, $constraint)) {
                        $problems[] = [$name, "{$name} needs PHP {$constraint}; this server runs ".PHP_VERSION.'.'];
                    }

                    continue;
                }

                if (str_starts_with($required, 'ext-')) {
                    if (! extension_loaded(substr($required, 4))) {
                        $problems[] = [$name, "{$name} needs PHP’s {$required} extension."];
                    }

                    continue;
                }

                $pinned = $pins['replace'][$required] ?? $pins['provide'][$required] ?? null;

                if ($pinned !== null && ! self::matches($parser, $pinned, $constraint)) {
                    $problems[] = [$name, "{$name} needs {$required} {$constraint}; this Teme has {$pinned}."];
                }
            }
        }

        return $problems;
    }

    /**
     * Whether what the image has meets what is required. A branch version
     * meets only `*` or itself.
     */
    private static function matches(VersionParser $parser, string $have, string $required): bool
    {
        if (trim($required) === '*' || $have === $required) {
            return true;
        }

        if (str_starts_with($have, 'dev-')) {
            return false;
        }

        try {
            return $parser->parseConstraints($required)->matches($parser->parseConstraints($have));
        } catch (Throwable) {
            return false;
        }
    }
}
