<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Plugins\Manifest;

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
     * The entry a plugin's manifest declares, owned by the plugin's code.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromManifest(Manifest $manifest, array $raw): self
    {
        $when = is_array($raw['when'] ?? null) ? $raw['when'] : null;
        $verify = is_array($raw['verify'] ?? null) ? $raw['verify'] : null;
        $cache = is_array($raw['cache'] ?? null) ? $raw['cache'] : null;
        $where = is_array($raw['where'] ?? null) ? array_filter($raw['where'], is_string(...)) : [];

        return new self(
            owner: $manifest->code(),
            id: is_string($raw['id'] ?? null) ? $raw['id'] : '',
            method: strtoupper(is_string($raw['method'] ?? null) ? $raw['method'] : 'GET'),
            path: is_string($raw['path'] ?? null) ? $raw['path'] : '',
            handler: ltrim(is_string($raw['handler'] ?? null) ? $raw['handler'] : '', '\\'),
            when: $when,
            verify: $verify,
            cache: $cache,
            maxBody: is_int($raw['maxBody'] ?? null) ? min(10_485_760, max(1, $raw['maxBody'])) : 1_048_576,
            throttle: is_int($raw['throttle'] ?? null) ? $raw['throttle'] : null,
            where: $where,
        );
    }

    /**
     * The query parameter names its condition reads.
     *
     * @return list<string>
     */
    public function conditionQueryNames(): array
    {
        $query = $this->when['query'] ?? null;

        return is_array($query) ? array_map(strval(...), array_keys($query)) : [];
    }

    /**
     * The Accept types its condition reads, lower-cased.
     *
     * @return list<string>
     */
    public function conditionAcceptTypes(): array
    {
        $accept = $this->when['accept'] ?? null;
        $types = [];

        foreach (is_array($accept) ? $accept : [] as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = strtolower($type);
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The path's named parameters when $path is this entry's, or null: the
     * literal parts compared as written, each `{name}` by its `where` pattern.
     *
     * @return array<string, string>|null
     */
    public function pathParameters(string $path): ?array
    {
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $this->resolvedPath(), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $named) === 1) {
                $regex .= '(?P<'.$named[1].'>'.str_replace('#', '\#', $this->where[$named[1]] ?? '[^/]+').')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        if (preg_match('#^'.$regex.'$#', $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * How the consent screen shows it: `METHOD path`, and its condition
     * when it has one (I-3353).
     */
    public function consentLine(): string
    {
        $line = $this->method.' '.$this->resolvedPath();
        $condition = [];

        foreach (is_array($this->when['query'] ?? null) ? $this->when['query'] : [] as $name => $pattern) {
            $condition[] = 'query '.$name.': '.(is_string($pattern) ? $pattern : '');
        }

        if ($this->conditionAcceptTypes() !== []) {
            $condition[] = 'Accept: '.implode(', ', $this->conditionAcceptTypes());
        }

        return $condition === [] ? $line : $line.' ('.implode('; ', $condition).')';
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
