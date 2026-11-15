<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

/**
 * One declared public address in the registry: who owns it, how it matches,
 * how POSTs are verified, and how GETs are cached.
 */
final readonly class DeclaredRoute
{
    public const string OWNER_CORE = 'core';

    /**
     * @param  array<string, mixed>|null  $when
     * @param  array<string, mixed>|null  $verify
     * @param  array<string, mixed>|null  $cache
     * @param  array<string, string>  $where
     */
    public function __construct(
        public string $owner,
        public string $id,
        public string $method,
        public string $path,
        public string $handler,
        public ?array $when = null,
        public ?array $verify = null,
        public ?array $cache = null,
        public int $maxBody = 1_048_576,
        public ?int $throttle = null,
        public array $where = [],
    ) {}

    public function entryKey(): string
    {
        return $this->owner.':'.$this->id;
    }

    public function isConditional(): bool
    {
        return $this->when !== null && $this->when !== [];
    }

    public function isCacheable(): bool
    {
        return $this->method === 'GET' && $this->cache !== null;
    }

    /**
     * The path with {code} replaced when the owner is a plugin.
     */
    public function resolvedPath(): string
    {
        if ($this->owner === self::OWNER_CORE) {
            return $this->path;
        }

        return str_replace('{code}', $this->owner, $this->path);
    }
}
