<?php

/**
 * What one ask of GitHub came to: a release list found, nothing changed since the last ETag, or a failure with the sentence that says why.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

final readonly class ReleaseCheck
{
    public const string FOUND = 'found';

    public const string UNCHANGED = 'unchanged';

    public const string FAILED = 'failed';

    /**
     * @param  list<Release>  $releases
     */
    private function __construct(
        public string $outcome,
        public array $releases,
        public ?string $etag,
        public ?string $error,
    ) {}

    /**
     * @param  list<Release>  $releases
     */
    public static function found(array $releases, ?string $etag): self
    {
        return new self(self::FOUND, $releases, $etag, null);
    }

    public static function unchanged(): self
    {
        return new self(self::UNCHANGED, [], null, null);
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, [], null, $error);
    }
}
