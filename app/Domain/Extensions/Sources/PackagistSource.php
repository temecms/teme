<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

/**
 * Packagist, Composer's own default source: it adds no repository and needs
 * no key, and Composer reads it unless the operator turns it off.
 */
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
