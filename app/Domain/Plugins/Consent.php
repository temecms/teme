<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

/**
 * What a person agreed to a plugin doing, against what its manifest asks for
 * now (I-3353): an update that asks for nothing more starts at once, and one
 * that asks for more waits until someone agrees. Declared public routes count
 * as access too: a new METHOD path, a GET turned into a POST, or a new
 * `.well-known` name needs another yes (`docs/plans/plugin-public-routes.md`).
 */
final readonly class Consent
{
    /**
     * Whether the access agreed to covers what the manifest asks for now: every
     * capability agreed, every host covered by an agreed host (HostPattern),
     * and every declared public route's METHOD path. Hooks are shown, never
     * agreed to: a new hook that needs no new capability needs no new yes.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>}  $consent
     */
    public static function covers(array $consent, Manifest $manifest): bool
    {
        if (array_diff($manifest->capabilities(), $consent['capabilities'] ?? []) !== []) {
            return false;
        }

        foreach ($manifest->hosts() as $host) {
            if (! HostPattern::anyCovers($consent['hosts'] ?? [], $host)) {
                return false;
            }
        }

        if (array_diff(self::routeLines($manifest), $consent['routes'] ?? []) !== []) {
            return false;
        }

        return true;
    }

    /**
     * The hosts the outbound door may reach: those the installed manifest
     * asks for that an agreed host covers, the narrower of the two. An
     * agreement wider than what the plugin asks for now gives it no more.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>}  $consent
     *
     * @return list<string>
     */
    public static function hosts(array $consent, Manifest $manifest): array
    {
        return array_values(array_filter($manifest->hosts(), fn (string $host): bool => HostPattern::anyCovers($consent['hosts'] ?? [], $host)));
    }

    /**
     * What the manifest asks for that was never agreed to: its new
     * capabilities, its hosts no agreed host covers, and its new route lines.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>}  $consent
     *
     * @return array{capabilities: list<string>, hosts: list<string>, routes: list<string>}
     */
    public static function added(array $consent, Manifest $manifest): array
    {
        return [
            'capabilities' => array_values(array_diff($manifest->capabilities(), $consent['capabilities'] ?? [])),
            'hosts' => array_values(array_filter($manifest->hosts(), fn (string $host): bool => ! HostPattern::anyCovers($consent['hosts'] ?? [], $host))),
            'routes' => array_values(array_diff(self::routeLines($manifest), $consent['routes'] ?? [])),
        ];
    }

    /**
     * Each declared public route as `METHOD path`, with `{code}` replaced by
     * the plugin's code, in the file's order. The consent screen lists these.
     *
     * @return list<string>
     */
    public static function routeLines(Manifest $manifest): array
    {
        $lines = [];

        foreach ($manifest->routes() as $route) {
            $method = strtoupper((string) ($route['method'] ?? ''));
            $path = (string) ($route['path'] ?? '');

            if ($method === '' || $path === '') {
                continue;
            }

            $lines[] = $method.' '.str_replace('{code}', $manifest->code(), $path);
        }

        return $lines;
    }
}
