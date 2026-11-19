<?php

/**
 * A vendor's own Composer source at an https address, such as a paid
 * plugin's, with the license key the vendor gives: a bearer token, or a
 * user name and key (I-433). The address is checked before Composer runs.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

final readonly class ComposerSource implements SourceKind
{
    public const string KEY = 'composer';

    public function __construct(private SafeAddress $addresses) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return $source?->url === null ? [] : [['type' => 'composer', 'url' => $source->url]];
    }

    public function auth(?ExtensionSource $source): array
    {
        $host = $source?->url === null ? null : parse_url($source->url, PHP_URL_HOST);

        if ($source?->credential === null || ! is_string($host) || $host === '') {
            return [];
        }

        $host = strtolower($host);

        return $source->username === null
            ? ['bearer' => [$host => $source->credential]]
            : ['http-basic' => [$host => ['username' => $source->username, 'password' => $source->credential]]];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void
    {
        $this->addresses->checkSource($request, self::KEY);
    }
}
