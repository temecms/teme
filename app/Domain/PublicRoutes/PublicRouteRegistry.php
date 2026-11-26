<?php

/**
 * Every declared public route from core and from enabled plugins granted
 * routes.public. Built on demand; forgotten when a plugin's state changes.
 * Core entries always win over an overlapping plugin entry.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use Illuminate\Http\Request;
use Teme\Contracts\Plugins\Capability;

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
        private readonly PluginLoader $loader,
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
     * The entry that answers this request, or null: its method, path, plain
     * query values and Accept types, as match() reads them.
     */
    public function matchRequest(Request $request): ?DeclaredRoute
    {
        $query = [];

        foreach ($request->query->all() as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return $this->match($request->getMethod(), $request->getPathInfo(), $query, self::acceptTypes($request));
    }

    /**
     * The request's Accept types, lower-cased, without parameters or the wildcard.
     *
     * @return list<string>
     */
    public static function acceptTypes(Request $request): array
    {
        $types = [];

        foreach (explode(',', (string) $request->headers->get('Accept', '')) as $part) {
            $type = strtolower(trim(explode(';', $part)[0]));

            if ($type !== '' && $type !== '*/*') {
                $types[] = $type;
            }
        }

        return $types;
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

        // HEAD is a GET without its body: a declared GET answers it.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

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
     * Query parameter names any enabled cacheable entry declares, plus the search's `s` and its `section`.
     *
     * @return list<string>
     */
    public function cacheQueryNames(): array
    {
        $names = ['s', 'section'];

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
     * Whether an uncacheable conditional entry on this path reads something
     * this request carries: one of its query names, or one of its Accept
     * types. Such a request is never answered from, or stored in, the cache.
     *
     * @param  array<string, string>  $query
     * @param  list<string>  $acceptTypes
     */
    public function uncacheableConditionTouches(string $path, array $query, array $acceptTypes): bool
    {
        $accept = array_map(strtolower(...), $acceptTypes);

        foreach ($this->all() as $route) {
            if ($route->isCacheable() || ! $route->isConditional() || ! $this->pathMatches($route, $path)) {
                continue;
            }

            if (array_intersect($route->conditionQueryNames(), array_map(strval(...), array_keys($query))) !== []) {
                return true;
            }

            if (array_intersect($route->conditionAcceptTypes(), $accept) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DeclaredRoute>
     */
    private function build(): array
    {
        if ($this->safeMode->active() || $this->loader->disabledOnServer() === true) {
            return $this->core;
        }

        return $this->from($this->records->enabled());
    }

    /**
     * Core's entries and every entry these enabled rows declare, whether or
     * not the plugin may load now: each row whose consent includes
     * routes.public and whose manifest is found, past the server's switch,
     * waiting consent or migrations and live errors. What a plugin's routes
     * are compared against, so one that loads later never makes two owners
     * answer one request.
     *
     * @param  list<PluginRecord>  $records
     *
     * @return list<DeclaredRoute>
     */
    public function declaredBy(array $records): array
    {
        $routes = $this->core;

        foreach ($records as $record) {
            if (! in_array(Capability::RoutesPublic->value, $record->consent['capabilities'] ?? [], true)) {
                continue;
            }

            $manifest = $this->plugins->find($record->name)?->manifest;

            foreach ($manifest?->routes() ?? [] as $raw) {
                $routes[] = DeclaredRoute::fromManifest($manifest, $raw);
            }
        }

        return $routes;
    }

    /**
     * Core's entries and those of these enabled plugins that may load now,
     * read from the rows given rather than the cached list.
     *
     * @param  list<PluginRecord>  $records
     *
     * @return list<DeclaredRoute>
     */
    public function from(array $records): array
    {
        $routes = $this->core;
        $disabledOnServer = $this->loader->disabledOnServer();

        if ($disabledOnServer === true) {
            return $routes;
        }

        foreach ($records as $record) {
            $capabilities = $record->consent['capabilities'] ?? [];

            if (! in_array(Capability::RoutesPublic->value, $capabilities, true)) {
                continue;
            }

            $found = $this->plugins->find($record->name);

            // The one rule that decides whether a plugin starts decides whether its routes answer.
            if ($found?->manifest === null || $this->loader->skipReason($record, $found, $disabledOnServer) !== null) {
                continue;
            }

            foreach ($found->manifest->routes() as $raw) {
                $routes[] = DeclaredRoute::fromManifest($found->manifest, $raw);
            }
        }

        return $routes;
    }

    private function pathMatches(DeclaredRoute $route, string $path): bool
    {
        return $route->pathParameters($path) !== null;
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

            if (preg_match('#'.str_replace('#', '\#', $pattern).'#', $query[$key]) !== 1) {
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
