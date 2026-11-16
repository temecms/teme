<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

/**
 * One kind of place packages come from (Packagist, a Git address, a vendor's
 * Composer source, an upload): what it adds to a release's composer.json,
 * its part of Composer's credentials, and its work before Composer runs.
 * Internal: Teme's signed directory is a later kind, with no change here.
 */
interface SourceKind
{
    /** The kind's key, stored in extension_sources.kind and extension_requests.source_kind. */
    public function key(): string;

    /**
     * Composer repository entries this source adds to the release's composer.json.
     *
     * @return list<array<string, mixed>>
     */
    public function repositories(?ExtensionSource $source, string $extensionsPath): array;

    /**
     * This source's part of COMPOSER_AUTH, decrypted only inside the installer.
     *
     * @return array<string, mixed>
     */
    public function auth(?ExtensionSource $source): array;

    /** Runs in the installer before Composer, in the new release's folder: an upload is unpacked here. */
    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void;
}
