<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\RootFile;

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
     * every declared public route's METHOD path, and every name it declares
     * to import. Hooks are shown, never agreed to: a new hook that needs no
     * new capability needs no new yes.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>, imports?: array<string, mixed>}  $consent
     */
    public static function covers(array $consent, Manifest $manifest): bool
    {
        if (array_diff($manifest->capabilities(), $consent['capabilities'] ?? []) !== []) {
            return false;
        }

        if (self::importsAdded($consent, $manifest) !== []) {
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

        // Hosts from another of its settings are wider access (docs/plans/plugin-sockets-1.md, A2).
        if (self::hostsFromAdded($consent, $manifest) !== []) {
            return false;
        }

        return true;
    }

    /**
     * The settings the outbound door may take hosts from: those the
     * manifest's `hostsFrom` names now that were also agreed, the narrower
     * of the two.
     *
     * @param  array{hostsFrom?: list<string>}  $consent
     *
     * @return list<string>
     */
    public static function hostsFrom(array $consent, Manifest $manifest): array
    {
        return array_values(array_intersect(SettingHosts::declared($manifest), (array) ($consent['hostsFrom'] ?? [])));
    }

    /**
     * The settings the manifest's `hostsFrom` names that were never agreed.
     *
     * @param  array{hostsFrom?: list<string>}  $consent
     *
     * @return list<string>
     */
    public static function hostsFromAdded(array $consent, Manifest $manifest): array
    {
        return array_values(array_diff(SettingHosts::declared($manifest), (array) ($consent['hostsFrom'] ?? [])));
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
     * capabilities, its hosts no agreed host covers, its new route lines,
     * and, only when there are any, the names it declares to import that
     * were never agreed.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>, imports?: array<string, mixed>}  $consent
     *
     * @return array{capabilities: list<string>, hosts: list<string>, routes: list<string>, imports?: array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}}
     */
    public static function added(array $consent, Manifest $manifest): array
    {
        $imports = self::importsAdded($consent, $manifest);

        $capabilities = array_values(array_diff($manifest->capabilities(), $consent['capabilities'] ?? []));

        // Hosts from another setting are more of http, so the screen asks again under it.
        if (self::hostsFromAdded($consent, $manifest) !== [] && ! in_array('http', $capabilities, true)) {
            $capabilities[] = 'http';
        }

        return [
            'capabilities' => $capabilities,
            'hosts' => array_values(array_filter($manifest->hosts(), fn (string $host): bool => ! HostPattern::anyCovers($consent['hosts'] ?? [], $host))),
            'routes' => array_values(array_diff(self::routeLines($manifest), $consent['routes'] ?? [])),
            ...($imports === [] ? [] : ['imports' => $imports]),
        ];
    }

    /**
     * Each declared public route as `METHOD path`, with `{code}` replaced by
     * the plugin's code and its condition, when it has one, in brackets, in
     * the file's order, then each root file it declares as `GET /name`. The
     * consent screen lists these, so a changed condition or a new root file
     * asks again too.
     *
     * @return list<string>
     */
    public static function routeLines(Manifest $manifest): array
    {
        $lines = [];

        foreach ($manifest->routes() as $raw) {
            $route = DeclaredRoute::fromManifest($manifest, $raw);

            if (! is_string($raw['method'] ?? null) || $route->path === '') {
                continue;
            }

            $lines[] = $route->consentLine();
        }

        foreach (RootFile::declaredIn($manifest) as $file) {
            $lines[] = $file->consentLine($manifest);
        }

        return $lines;
    }

    /**
     * The names the manifest declares to import, as the consent record keeps
     * them: without the importer class, and empty when it declares none.
     *
     * @return array{}|array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}
     */
    public static function declaredImports(Manifest $manifest): array
    {
        $declared = $manifest->imports();
        unset($declared['importer']);

        return $declared['options'] === [] && $declared['postTypes'] === [] && $declared['userMeta'] === [] && $declared['termMeta'] === [] ? [] : $declared;
    }

    /**
     * The names agreed to import, as the consent record keeps them.
     *
     * @param  array{imports?: array<string, mixed>}  $consent
     *
     * @return array{importer: string, options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}
     */
    public static function agreedImports(array $consent): array
    {
        return (new Manifest(['imports' => $consent['imports'] ?? []]))->imports();
    }

    /**
     * The names an import may hand the plugin: those its manifest declares
     * now that were also agreed to, the narrower of the two (I-349).
     *
     * @param  array{imports?: array<string, mixed>}  $consent
     *
     * @return array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}
     */
    public static function imports(array $consent, Manifest $manifest): array
    {
        return self::splitImports($consent, $manifest)[0];
    }

    /**
     * @param  array{imports?: array<string, mixed>}  $consent
     *
     * @return array{}|array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}
     */
    private static function importsAdded(array $consent, Manifest $manifest): array
    {
        $added = self::splitImports($consent, $manifest)[1];

        return $added['options'] === [] && $added['postTypes'] === [] && $added['userMeta'] === [] && $added['termMeta'] === [] ? [] : $added;
    }

    /**
     * The declared names split into those agreed and those never agreed.
     *
     * @param  array{imports?: array<string, mixed>}  $consent
     *
     * @return array{0: array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}, 1: array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}}
     */
    private static function splitImports(array $consent, Manifest $manifest): array
    {
        $declared = $manifest->imports();
        $agreed = self::agreedImports($consent);
        $kept = ['options' => [], 'postTypes' => [], 'userMeta' => [], 'termMeta' => []];
        $new = $kept;

        foreach (['options', 'userMeta', 'termMeta'] as $kind) {
            $kept[$kind] = array_values(array_intersect($declared[$kind], $agreed[$kind]));
            $new[$kind] = array_values(array_diff($declared[$kind], $agreed[$kind]));
        }

        foreach ($declared['postTypes'] as $type => $meta) {
            $agreedMeta = $agreed['postTypes'][$type] ?? null;

            if ($agreedMeta === null) {
                $new['postTypes'][$type] = $meta;

                continue;
            }

            $kept['postTypes'][$type] = array_values(array_intersect($meta, $agreedMeta));

            if (array_diff($meta, $agreedMeta) !== []) {
                $new['postTypes'][$type] = array_values(array_diff($meta, $agreedMeta));
            }
        }

        return [$kept, $new];
    }
}
