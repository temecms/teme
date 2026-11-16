<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\RootFile;

/**
 * What a person agreed to a plugin doing, against what its manifest asks for
 * now (I-3353): an update that asks for nothing more starts at once, and one
 * that asks for more waits until someone agrees. Declared public routes count
 * as access too: a new METHOD path, a GET turned into a POST, or a new
 * `.well-known` name needs another yes (`docs/plans/plugin-public-routes.md`).
 * So do the hosts a plugin that inserts content declares for it to load from
 * (`contributes.csp`, docs/plans/plugin-sockets-2.md, B3), and the kinds of
 * notice it sends and the plugins it requires (docs/plans/plugin-sockets-3.md).
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
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>, imports?: array<string, mixed>, csp?: array<string, list<string>>}  $consent
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

        if (self::insertionHostsAdded($consent, $manifest) !== []) {
            return false;
        }

        return self::noticesAdded($consent, $manifest) === [] && self::requiresAdded($consent, $manifest) === [];
    }

    /**
     * The ids of the kinds of notice the manifest declares, as the consent
     * record keeps them.
     *
     * @return list<string>
     */
    public static function declaredNotices(Manifest $manifest): array
    {
        return array_map(strval(...), array_keys(DeclaredNotices::of($manifest)));
    }

    /**
     * The Composer names of the plugins the manifest requires, as the
     * consent record keeps them.
     *
     * @return list<string>
     */
    public static function declaredRequires(Manifest $manifest): array
    {
        return array_map(strval(...), array_keys(Dependencies::required($manifest)));
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
     * The hosts the manifest's `csp` declares for the content the plugin
     * inserts, by kind (script, frame, img and the rest), in lower case;
     * kinds it lists none for are absent.
     *
     * @return array<string, list<string>>
     */
    public static function declaredInsertionHosts(Manifest $manifest): array
    {
        $csp = $manifest->contributes()['csp'] ?? null;
        $declared = [];

        foreach (is_array($csp) ? $csp : [] as $kind => $hosts) {
            $listed = array_values(array_unique(array_filter(array_map(fn (mixed $host): string => is_string($host) ? strtolower($host) : '', is_array($hosts) ? $hosts : []), fn (string $host): bool => preg_match(HostPattern::PATTERN, $host) === 1)));

            if (is_string($kind) && $listed !== []) {
                $declared[$kind] = $listed;
            }
        }

        ksort($declared);

        return $declared;
    }

    /**
     * The hosts inserted content may load from, by kind: those the manifest
     * declares now that an agreed host of the same kind covers, the narrower
     * of the two.
     *
     * @param  array{csp?: array<string, list<string>>}  $consent
     *
     * @return array<string, list<string>>
     */
    public static function insertionHosts(array $consent, Manifest $manifest): array
    {
        $kept = [];

        foreach (self::declaredInsertionHosts($manifest) as $kind => $hosts) {
            $agreed = array_values(array_filter((array) ($consent['csp'][$kind] ?? []), 'is_string'));
            $covered = array_values(array_filter($hosts, fn (string $host): bool => HostPattern::anyCovers($agreed, $host)));

            if ($covered !== []) {
                $kept[$kind] = $covered;
            }
        }

        return $kept;
    }

    /**
     * The hosts for inserted content the manifest declares that no agreed
     * host of the same kind covers, each once.
     *
     * @param  array{csp?: array<string, list<string>>}  $consent
     *
     * @return list<string>
     */
    public static function insertionHostsAdded(array $consent, Manifest $manifest): array
    {
        $added = [];

        foreach (self::declaredInsertionHosts($manifest) as $kind => $hosts) {
            $agreed = array_values(array_filter((array) ($consent['csp'][$kind] ?? []), 'is_string'));

            foreach ($hosts as $host) {
                if (! HostPattern::anyCovers($agreed, $host)) {
                    $added[$host] = true;
                }
            }
        }

        return array_keys($added);
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
     * and, only when there are any, the hosts its inserted content loads
     * from and the names it declares to import that were never agreed.
     *
     * @param  array{capabilities?: list<string>, hosts?: list<string>, routes?: list<string>, imports?: array<string, mixed>, csp?: array<string, list<string>>}  $consent
     *
     * @return array{capabilities: list<string>, hosts: list<string>, routes: list<string>, insertionHosts?: list<string>, imports?: array{options: list<string>, postTypes: array<string, list<string>>, userMeta: list<string>, termMeta: list<string>}, notices?: list<string>, requires?: list<string>}
     */
    public static function added(array $consent, Manifest $manifest): array
    {
        $imports = self::importsAdded($consent, $manifest);
        $insertionHosts = self::insertionHostsAdded($consent, $manifest);
        $notices = self::noticesAdded($consent, $manifest);
        $requires = self::requiresAdded($consent, $manifest);

        $capabilities = array_values(array_diff($manifest->capabilities(), $consent['capabilities'] ?? []));

        // Hosts from another setting are more of http, so the screen asks again under it.
        if (self::hostsFromAdded($consent, $manifest) !== [] && ! in_array('http', $capabilities, true)) {
            $capabilities[] = 'http';
        }

        return [
            'capabilities' => $capabilities,
            'hosts' => array_values(array_filter($manifest->hosts(), fn (string $host): bool => ! HostPattern::anyCovers($consent['hosts'] ?? [], $host))),
            'routes' => array_values(array_diff(self::routeLines($manifest), $consent['routes'] ?? [])),
            ...($insertionHosts === [] ? [] : ['insertionHosts' => $insertionHosts]),
            ...($imports === [] ? [] : ['imports' => $imports]),
            ...($notices === [] ? [] : ['notices' => $notices]),
            ...($requires === [] ? [] : ['requires' => $requires]),
        ];
    }

    /**
     * The kinds of notice declared that were never agreed to.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<string>
     */
    private static function noticesAdded(array $consent, Manifest $manifest): array
    {
        return array_values(array_diff(self::declaredNotices($manifest), (array) ($consent['notices'] ?? [])));
    }

    /**
     * The plugins required that were never agreed to.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<string>
     */
    private static function requiresAdded(array $consent, Manifest $manifest): array
    {
        return array_values(array_diff(self::declaredRequires($manifest), (array) ($consent['requires'] ?? [])));
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
