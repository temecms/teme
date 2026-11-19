<?php

/**
 * The directory's index.json once its signature is checked: its number,
 * when it was made and runs out, each listed package, and the hash of every
 * file it names, so the signature covers those files too.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Directory;

use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

final readonly class DirectoryIndex
{
    /** The index format this Teme reads. */
    public const int FORMAT = 1;

    /** A file path inside the directory: no scheme, no parent folder, no leading slash. */
    private const string PATH = '{^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]{1,300}$}';

    /**
     * @param  list<array<string, mixed>>  $packages  each package as listed, its name, kind, detail path and detail hash checked
     */
    public function __construct(
        public int $sequence,
        public CarbonImmutable $generated,
        public CarbonImmutable $expires,
        public array $packages,
        public string $composerPath,
        public string $composerSha256,
    ) {}

    /**
     * Reads index.json's bytes, or its decoded copy kept after a check.
     *
     * @param  string|array<mixed>  $json
     *
     * @throws DirectoryRefused when it isn't in the shape the directory writes
     */
    public static function read(string|array $json): self
    {
        if (is_string($json)) {
            try {
                $json = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw DirectoryRefused::malformed('index.json');
            }
        }

        if (! is_array($json) || ($json['format'] ?? null) !== self::FORMAT || ! is_int($json['sequence'] ?? null) || $json['sequence'] < 0
            || ! is_array($json['packages'] ?? null) || ! array_is_list($json['packages']) || ! is_array($json['composer'] ?? null)) {
            throw DirectoryRefused::malformed('index.json');
        }

        $composerPath = $json['composer']['path'] ?? null;
        $composerSha = $json['composer']['sha256'] ?? null;

        if (! self::isPath($composerPath) || ! self::isSha256($composerSha)) {
            throw DirectoryRefused::malformed('index.json');
        }

        $packages = [];

        foreach ($json['packages'] as $package) {
            if (! is_array($package)
                || ! is_string($package['name'] ?? null) || preg_match(RequestExtensionChange::PACKAGE_NAME, $package['name']) !== 1
                || ExtensionKind::tryFrom(is_string($package['kind'] ?? null) ? $package['kind'] : '') === null
                || ! self::isPath($package['detail'] ?? null) || ! self::isSha256($package['detail_sha256'] ?? null)) {
                throw DirectoryRefused::malformed('index.json');
            }

            $packages[] = $package;
        }

        return new self(
            sequence: $json['sequence'],
            generated: self::moment($json['generated'] ?? null),
            expires: self::moment($json['expires'] ?? null),
            packages: $packages,
            composerPath: $composerPath,
            composerSha256: $composerSha,
        );
    }

    /**
     * Returns the listed package of this name and kind, or null.
     *
     * @return array<string, mixed>|null
     */
    public function package(string $name, ?ExtensionKind $kind = null): ?array
    {
        foreach ($this->packages as $package) {
            if ($package['name'] === $name && ($kind === null || $package['kind'] === $kind->value)) {
                return $package;
            }
        }

        return null;
    }

    /** Whether the index has run out at this moment. */
    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    private static function moment(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw DirectoryRefused::malformed('index.json');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw DirectoryRefused::malformed('index.json');
        }
    }

    /** @phpstan-assert-if-true string $value */
    private static function isPath(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATH, $value) === 1;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }
}
