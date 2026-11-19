<?php

/**
 * A Git repository at an https address, public or private with a token:
 * Composer reads it as a vcs repository, with the token as the host's
 * credential, and the address is checked before Composer runs (I-211).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

final readonly class GitSource implements SourceKind
{
    public const string KEY = 'git';

    public function __construct(private SafeAddress $addresses) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return $source?->url === null ? [] : [['type' => 'vcs', 'url' => $source->url]];
    }

    public function auth(?ExtensionSource $source): array
    {
        $host = $source?->url === null ? null : parse_url($source->url, PHP_URL_HOST);

        if ($source?->credential === null || ! is_string($host) || $host === '') {
            return [];
        }

        $host = strtolower($host);

        return match ($host) {
            'github.com' => ['github-oauth' => ['github.com' => $source->credential]],
            'gitlab.com' => ['gitlab-token' => ['gitlab.com' => $source->credential]],
            default => ['http-basic' => [$host => ['username' => $source->username ?? 'token', 'password' => $source->credential]]],
        };
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void
    {
        $this->addresses->checkSource($request, self::KEY);
    }
}
