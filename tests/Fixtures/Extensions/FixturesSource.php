<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\SourceKind;

/**
 * A package source for the installer's tests: the fixture packages as one
 * path repository, so no test reaches Packagist.
 */
final readonly class FixturesSource implements SourceKind
{
    public function key(): string
    {
        return ExtensionsFolder::SOURCE;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [ExtensionsFolder::repository()];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void {}
}
