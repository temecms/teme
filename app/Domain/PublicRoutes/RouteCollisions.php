<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Addresses\Paths;
use App\Domain\Plugins\Manifest;

/**
 * The refusals (docs/plans/plugin-public-routes.md) for a plugin's declared public routes: reserved paths,
 * unconditional paths outside /{code}/ and /.well-known/, core .well-known
 * names and public addresses, missing POST verifiers, unknown secret
 * settings, and two entries that could match one request.
 */
final class RouteCollisions
{
    /** Prefixes a plugin may not claim (Settled §3). */
    public const array RESERVED_PREFIXES = ['admin', '_teme', 'api', 'up', 'build', 'storage'];

    /** .well-known names that belong to core (P3). */
    public const array CORE_WELL_KNOWN = ['webfinger', 'nodeinfo', 'host-meta', 'security.txt', 'change-password'];

    /** Core public path names a plugin may not take. */
    public const array CORE_PUBLIC = [
        'robots.txt',
        'sitemap.xml',
        'sitemap.xsl',
        'llms.txt',
        'feed',
        'wp-sitemap.xml',
        'sitemap_index.xml',
    ];

    /**
     * Every problem in this plugin's routes alone; empty when the list is fine.
     *
     * @param  list<array<string, mixed>>  $routes
     *
     * @return list<string>
     */
    public function errorsFor(Manifest $manifest, array $routes): array
    {
        $errors = [];
        $seen = [];

        foreach ($routes as $route) {
            $id = is_string($route['id'] ?? null) ? $route['id'] : '';
            $method = is_string($route['method'] ?? null) ? strtoupper($route['method']) : '';
            $path = is_string($route['path'] ?? null) ? $route['path'] : '';
            $handler = is_string($route['handler'] ?? null) ? $route['handler'] : '';

            if ($id === '' || preg_match('/^[a-z][a-z0-9_-]*$/', $id) !== 1) {
                $errors[] = 'Each route needs an id of lower-case letters, digits, hyphens and underscores.';
            }

            if (! in_array($method, ['GET', 'POST'], true)) {
                $errors[] = 'Each route’s method must be GET or POST.';
            }

            if ($path === '' || ! str_starts_with($path, '/')) {
                $errors[] = 'Each route needs a path that starts with /.';
            } else {
                array_push($errors, ...$this->pathErrors($manifest->code(), $path, $route));
            }

            if ($handler === '') {
                $errors[] = 'Each route needs a handler class.';
            }

            if ($method === 'POST') {
                array_push($errors, ...$this->verifyErrors($manifest, $route));
            }

            array_push($errors, ...$this->cacheErrors($route));

            $resolved = str_replace('{code}', $manifest->code(), $path);
            $key = $method.' '.$resolved.' '.$this->conditionKey($route);

            if (isset($seen[$key])) {
                $errors[] = "This route collides with {$manifest->code()}’s {$seen[$key]}.";
            } elseif ($id !== '') {
                $seen[$key] = $id;
            }
        }

        return $errors;
    }

    /**
     * Every collision between this manifest's routes and the entries that
     * are declared, core's and every other enabled plugin's, loading now or
     * not: the same method on a path both could match, with conditions that
     * can overlap, or a `.well-known` name
     * another owner holds. The plugin's own entries are left out, so
     * enabling it again after an update compares only against others.
     *
     * @param  list<DeclaredRoute>  $enabled
     *
     * @return list<string>
     */
    public function against(array $enabled, Manifest $manifest): array
    {
        $errors = [];

        foreach ($manifest->routes() as $raw) {
            $candidate = DeclaredRoute::fromManifest($manifest, $raw);
            $wellKnown = $this->wellKnownName($candidate);

            foreach ($enabled as $other) {
                if ($other->owner === $candidate->owner) {
                    continue;
                }

                if ($wellKnown !== null && $wellKnown === $this->wellKnownName($other)) {
                    $errors[] = __('The .well-known name :name belongs to :owner.', ['name' => $wellKnown, 'owner' => $other->owner]);

                    continue;
                }

                if ($other->method === $candidate->method && $this->pathsOverlap($candidate, $other) && $this->conditionsOverlap($candidate, $other)) {
                    $errors[] = __('This route collides with :owner’s :id.', ['owner' => $other->owner, 'id' => $other->id]);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Every literal root-file name in this manifest that is one of core's
     * own public addresses (docs/guides/developers/plugins/root-files.md); empty when there is none.
     *
     * @return list<string>
     */
    public function rootFileErrors(Manifest $manifest): array
    {
        $errors = [];

        foreach (RootFile::declaredIn($manifest) as $file) {
            if ($file->nameSetting() === null && (in_array($file->name, self::CORE_PUBLIC, true) || in_array($file->name, Paths::SITE_ROUTES, true))) {
                $errors[] = "The root file {$file->name} is a core public address.";
            }
        }

        return $errors;
    }

    /**
     * Every literal root-file name this manifest shares with a literal name
     * another enabled plugin declares, naming its owner. The plugin's own
     * manifest is left out, so enabling it again compares only against others.
     *
     * @param  list<Manifest>  $enabled  the enabled plugins' manifests
     *
     * @return list<string>
     */
    public function rootFilesAgainst(array $enabled, Manifest $manifest): array
    {
        $errors = [];

        foreach (RootFile::declaredIn($manifest) as $file) {
            if ($file->nameSetting() !== null) {
                continue;
            }

            foreach ($enabled as $other) {
                if ($other->name() === $manifest->name()) {
                    continue;
                }

                foreach (RootFile::declaredIn($other) as $theirs) {
                    if ($theirs->nameSetting() === null && $theirs->name === $file->name) {
                        $errors[] = __('The root file /:name belongs to :owner.', ['name' => $file->name, 'owner' => $other->name()]);
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Whether one request could meet both entries' conditions: an entry with
     * no condition meets every request, and two conditions overlap unless
     * both name query parameters and share none, or both name Accept types
     * and share none.
     */
    private function conditionsOverlap(DeclaredRoute $a, DeclaredRoute $b): bool
    {
        if (! $a->isConditional() || ! $b->isConditional()) {
            return true;
        }

        $queryA = $a->conditionQueryNames();
        $queryB = $b->conditionQueryNames();

        if ($queryA !== [] && $queryB !== [] && array_intersect($queryA, $queryB) === []) {
            return false;
        }

        $acceptA = $a->conditionAcceptTypes();
        $acceptB = $b->conditionAcceptTypes();

        return ! ($acceptA !== [] && $acceptB !== [] && array_intersect($acceptA, $acceptB) === []);
    }

    /**
     * Whether one path could be both entries': segment by segment, two
     * literals are equal, a literal is one the other's parameter pattern
     * matches, and two parameters may meet.
     */
    private function pathsOverlap(DeclaredRoute $a, DeclaredRoute $b): bool
    {
        $segmentsA = explode('/', $a->resolvedPath());
        $segmentsB = explode('/', $b->resolvedPath());

        if (count($segmentsA) !== count($segmentsB)) {
            return false;
        }

        foreach ($segmentsA as $index => $segmentA) {
            $segmentB = $segmentsB[$index];
            $patternA = $this->parameterPattern($a, $segmentA);
            $patternB = $this->parameterPattern($b, $segmentB);

            $meets = match (true) {
                $patternA !== null && $patternB !== null => true,
                $patternA !== null => preg_match('#^(?:'.$patternA.')$#', $segmentB) === 1,
                $patternB !== null => preg_match('#^(?:'.$patternB.')$#', $segmentA) === 1,
                default => $segmentA === $segmentB,
            };

            if (! $meets) {
                return false;
            }
        }

        return true;
    }

    /**
     * The segment's pattern when it is a whole `{name}` parameter, or null for a literal.
     */
    private function parameterPattern(DeclaredRoute $route, string $segment): ?string
    {
        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $named) !== 1) {
            return null;
        }

        return str_replace('#', '\#', $route->where[$named[1]] ?? '[^/]+');
    }

    private function wellKnownName(DeclaredRoute $route): ?string
    {
        $path = $route->resolvedPath();

        if (! str_starts_with($path, '/.well-known/')) {
            return null;
        }

        $name = explode('/', substr($path, strlen('/.well-known/')))[0];

        return $name === '' ? null : $name;
    }

    /**
     * A cacheable entry's key must carry everything its condition reads:
     * varyAccept when it matches on Accept, and every condition query name.
     *
     * @param  array<string, mixed>  $route
     *
     * @return list<string>
     */
    private function cacheErrors(array $route): array
    {
        $cache = $route['cache'] ?? null;
        $when = $route['when'] ?? null;

        if (! is_array($cache) || ! is_array($when) || strtoupper(is_string($route['method'] ?? null) ? $route['method'] : '') !== 'GET') {
            return [];
        }

        $errors = [];
        $accept = $when['accept'] ?? null;

        if (is_array($accept) && $accept !== [] && ($cache['varyAccept'] ?? false) !== true) {
            $errors[] = __('A cached route that matches on Accept needs cache.varyAccept.');
        }

        $named = is_array($cache['query'] ?? null) ? $cache['query'] : [];
        $missing = array_values(array_diff(array_map(strval(...), array_keys(is_array($when['query'] ?? null) ? $when['query'] : [])), array_map(strval(...), array_filter($named, is_string(...)))));

        if ($missing !== []) {
            $errors[] = __('cache.query must name every when.query parameter; it leaves out :names.', ['names' => implode(', ', $missing)]);
        }

        return $errors;
    }

    /**
     * Whether this unconditional resolved path is under the plugin’s code or
     * /.well-known/.
     */
    public function unconditionalAllowed(string $resolvedPath, string $code): bool
    {
        if (str_starts_with($resolvedPath, '/.well-known/')) {
            return true;
        }

        return str_starts_with($resolvedPath, '/'.$code.'/') || $resolvedPath === '/'.$code;
    }

    /**
     * @param  array<string, mixed>  $route
     *
     * @return list<string>
     */
    private function pathErrors(string $code, string $path, array $route): array
    {
        $resolved = str_replace('{code}', $code, $path);
        $trimmed = ltrim($resolved, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);
        $first = $segments[0] ?? '';
        $when = $route['when'] ?? null;
        $conditional = is_array($when) && $when !== [];

        if ($first !== '' && in_array($first, self::RESERVED_PREFIXES, true)) {
            return ["The path {$resolved} is reserved."];
        }

        if (in_array($trimmed, self::CORE_PUBLIC, true) || str_starts_with($trimmed, 'sitemap-')) {
            return ["The path {$resolved} is a core public address."];
        }

        if (str_starts_with($resolved, '/.well-known/')) {
            $wellKnown = substr($resolved, strlen('/.well-known/'));
            $name = $wellKnown === '' ? '' : explode('/', $wellKnown)[0];

            if (in_array($name, self::CORE_WELL_KNOWN, true)) {
                return ["The .well-known name {$name} belongs to core."];
            }

            return [];
        }

        if (! $conditional && ! $this->unconditionalAllowed($resolved, $code)) {
            return ['An unconditional path must be under /{code}/ or /.well-known/.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $route
     *
     * @return list<string>
     */
    private function verifyErrors(Manifest $manifest, array $route): array
    {
        $verify = $route['verify'] ?? null;

        if (! is_array($verify) || $verify === []) {
            return ['A POST route needs a verify block.'];
        }

        $kind = is_string($verify['kind'] ?? null) ? $verify['kind'] : '';

        if (! in_array($kind, ['secret', 'hmac', 'custom'], true)) {
            return ['verify.kind must be secret, hmac or custom.'];
        }

        if ($kind === 'custom' && (! is_string($verify['class'] ?? null) || $verify['class'] === '')) {
            return ['A custom verifier needs a class.'];
        }

        $secret = is_string($verify['secret'] ?? null) ? $verify['secret'] : '';

        // A custom verifier may do without a secret; secret and hmac can't.
        if ($secret === '') {
            return $kind === 'custom' ? [] : ['verify needs a secret setting name.'];
        }

        $keys = [];

        foreach ($manifest->contributes()['settings'] ?? [] as $setting) {
            if (is_array($setting) && ($setting['type'] ?? null) === 'secret' && is_string($setting['key'] ?? null)) {
                $keys[] = $setting['key'];
            }
        }

        if (! in_array($secret, $keys, true)) {
            return ["verify.secret \"{$secret}\" isn’t a declared secret setting."];
        }

        if ($kind === 'secret') {
            $from = is_string($verify['from'] ?? null) ? $verify['from'] : '';

            if (! preg_match('/^(header|field|query):.+$/', $from)) {
                return ['verify.from must be header:, field: or query: and a name.'];
            }
        }

        if ($kind === 'hmac' && (! is_string($verify['header'] ?? null) || $verify['header'] === '')) {
            return ['An hmac verifier needs a header name.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function conditionKey(array $route): string
    {
        $when = $route['when'] ?? null;

        if (! is_array($when) || $when === []) {
            return '';
        }

        return json_encode($when, JSON_THROW_ON_ERROR);
    }
}
