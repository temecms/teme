<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use Teme\Contracts\Plugins\Capability;

/**
 * Every declared public route from core and from enabled plugins granted
 * routes.public. Built on demand; forgotten when a plugin's state changes.
 * Core entries always win over an overlapping plugin entry.
 */
final class PublicRouteRegistry
{
    /** @var list<DeclaredRoute> */
    private array $core = [];

    /** @var list<DeclaredRoute>|null */
    private ?array $compiled = null;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginRecords $records,
        private readonly SafeMode $safeMode,
    ) {}

    /**
     * Registers a core-owned entry. Call from a service provider's boot.
     */
    public function registerCore(DeclaredRoute $route): void
    {
        $this->core[] = $route;
        $this->compiled = null;
    }

    /**
     * Forgets the compiled list so the next read asks the plugins again.
     */
    public function flush(): void
    {
        $this->compiled = null;
    }

    /**
     * Every entry that may answer a request right now.
     *
     * @return list<DeclaredRoute>
     */
    public function all(): array
    {
        return $this->compiled ??= $this->build();
    }

    /**
     * The first matching entry for this method and path, or null.
     *
     * @param  array<string, string>  $query
     * @param  list<string>  $acceptTypes
     */
    public function match(string $method, string $path, array $query = [], array $acceptTypes = []): ?DeclaredRoute
    {
        $method = strtoupper($method);
        $pluginMatch = null;

        foreach ($this->all() as $route) {
            if ($route->method !== $method || ! $this->pathMatches($route, $path)) {
                continue;
            }

            if (! $this->conditionsMatch($route, $query, $acceptTypes)) {
                continue;
            }

            if ($route->owner === DeclaredRoute::OWNER_CORE) {
                return $route;
            }

            $pluginMatch ??= $route;
        }

        return $pluginMatch;
    }

    /**
     * Query parameter names any enabled cacheable entry declares, plus `s`.
     *
     * @return list<string>
     */
    public function cacheQueryNames(): array
    {
        $names = ['s'];

        foreach ($this->all() as $route) {
            if (! $route->isCacheable()) {
                continue;
            }

            foreach ($route->cache['query'] ?? [] as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether any enabled entry with varyAccept exists for this path, and
     * which Accept types those entries declare.
     *
     * @return list<string>
     */
    public function acceptTypesFor(string $path): array
    {
        $types = [];

        foreach ($this->all() as $route) {
            if (! $route->isCacheable() || ! ($route->cache['varyAccept'] ?? false)) {
                continue;
            }

            if (! $this->pathMatches($route, $path)) {
                continue;
            }

            foreach ($route->when['accept'] ?? [] as $type) {
                if (! is_string($type) || $type === '') {
                    continue;
                }

                $types[] = strtolower($type);
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Whether the request's query names a conditional entry's parameter whose
     * entry is not cacheable — such a request must never be served from cache.
     *
     * @param  array<string, string>  $query
     */
    public function queryNamesConditionalUncacheable(string $path, array $query): bool
    {
        foreach ($this->all() as $route) {
            if ($route->isCacheable() || ! $route->isConditional() || ! $this->pathMatches($route, $path)) {
                continue;
            }

            foreach (array_keys($route->when['query'] ?? []) as $name) {
                if (array_key_exists((string) $name, $query)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<DeclaredRoute>
     */
    private function build(): array
    {
        if ($this->safeMode->active()) {
            return $this->core;
        }

        $routes = $this->core;

        foreach ($this->records->enabled() as $record) {
            $capabilities = $record->consent['capabilities'] ?? [];

            if (! in_array(Capability::RoutesPublic->value, $capabilities, true)) {
                continue;
            }

            $found = $this->plugins->find($record->name);

            if ($found?->manifest === null || $found->problems !== []) {
                continue;
            }

            foreach ($found->manifest->routes() as $raw) {
                $routes[] = $this->fromManifest($found->manifest, $raw);
            }
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function fromManifest(Manifest $manifest, array $raw): DeclaredRoute
    {
        $when = is_array($raw['when'] ?? null) ? $raw['when'] : null;
        $verify = is_array($raw['verify'] ?? null) ? $raw['verify'] : null;
        $cache = is_array($raw['cache'] ?? null) ? $raw['cache'] : null;
        $where = is_array($raw['where'] ?? null) ? array_filter($raw['where'], is_string(...)) : [];

        return new DeclaredRoute(
            owner: $manifest->code(),
            id: (string) ($raw['id'] ?? ''),
            method: strtoupper((string) ($raw['method'] ?? 'GET')),
            path: (string) ($raw['path'] ?? ''),
            handler: ltrim((string) ($raw['handler'] ?? ''), '\\'),
            when: $when,
            verify: $verify,
            cache: $cache,
            maxBody: is_int($raw['maxBody'] ?? null) ? min(10_485_760, max(1, $raw['maxBody'])) : 1_048_576,
            throttle: is_int($raw['throttle'] ?? null) ? $raw['throttle'] : null,
            where: $where,
        );
    }

    private function pathMatches(DeclaredRoute $route, string $path): bool
    {
        $pattern = $route->resolvedPath();
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use ($route): string {
            $name = $m[1];
            $rule = $route->where[$name] ?? '[^/]+';

            return '(?P<'.$name.'>'.$rule.')';
        }, $pattern);

        return preg_match('#^'.str_replace('#', '\#', (string) $regex).'$#', $path) === 1;
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<string>  $acceptTypes
     */
    private function conditionsMatch(DeclaredRoute $route, array $query, array $acceptTypes): bool
    {
        if (! $route->isConditional()) {
            return true;
        }

        foreach ($route->when['query'] ?? [] as $name => $pattern) {
            $key = (string) $name;

            if (! is_string($pattern) || ! array_key_exists($key, $query)) {
                return false;
            }

            if (preg_match('/'.$pattern.'/', $query[$key]) !== 1) {
                return false;
            }
        }

        $accept = $route->when['accept'] ?? null;

        if (is_array($accept) && $accept !== []) {
            $wanted = [];

            foreach ($accept as $type) {
                if (is_string($type) && $type !== '') {
                    $wanted[] = strtolower($type);
                }
            }

            if ($wanted !== [] && array_intersect($wanted, array_map(strtolower(...), $acceptTypes)) === []) {
                return false;
            }
        }

        return true;
    }
}
