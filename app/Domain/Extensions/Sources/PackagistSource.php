<?php

/**
 * Packagist, Composer's own default source: it adds no repository and needs
 * no key, and Composer reads it unless the operator turns it off.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

final readonly class PackagistSource implements SourceKind
{
    public const string KEY = 'packagist';

    public function key(): string
    {
        return self::KEY;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void {}
}
