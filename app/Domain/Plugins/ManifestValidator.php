<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Hooks\Reference\HookCatalogue;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\PublicRoutes\RouteCollisions;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use Composer\Semver\Semver;
use ReflectionClass;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Plugins\Capability;
use UnexpectedValueException;

/**
 * Checks a teme-plugin.json against manifest version 1, and says in plain
 * words what is wrong (I-3353). The plugin's class is read from its source,
 * never loaded, so checking a plugin runs none of its code. A plugin with any
 * problem can't be used. Duplicate codes need the other plugins, so
 * PluginRegistry checks those.
 */
final readonly class ManifestValidator
{
    /** A plugin's Composer name, Composer's own pattern, between delimiters that aren't in it. */
    public const string NAME = '~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~';

    /** A plugin's version: three numbers, then -alpha.N, -beta.N or -rc.N for a pre-release. */
    public const string VERSION = '/^\d+\.\d+\.\d+(-(alpha|beta|rc)\.\d+)?$/';

    private const string CODE = '/^'.ThemeManifestValidator::CODE_PATTERN.'$/';

    /** The capabilities docs/architecture/plugins.md names that have no door yet. */
    private const array LATER = ['content.write', 'media.write', 'people.read', 'mail.send', 'site.output', 'ai'];

    public function __construct(
        private CodeScan $scan,
        private ContributionsCheck $contributions,
        private RouteCollisions $routeCollisions = new RouteCollisions,
    ) {}

    /**
     * Every problem in the manifest, in the order the keys are checked;
     * empty when it is valid. $composer is the plugin's composer.json, with
     * Composer's own name for an installed package; null for a folder
     * without one.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composer
     *
     * @return list<string>
     */
    public function errors(array $manifest, string $path, ?array $composer): array
    {
        return $this->errorsGiven($manifest, $composer, $this->classReading($manifest, $path, $composer));
    }

    /**
     * What the plugin's class says of itself, from CodeScan::pluginClass(),
     * or null when the manifest names no class or no file declares it: what
     * the plugin cache keeps so a request never reads the class again.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composer
     *
     * @return array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null
     */
    public function classReading(array $manifest, string $path, ?array $composer): ?array
    {
        if (! is_string($manifest['plugin'] ?? null) || trim($manifest['plugin']) === '') {
            return null;
        }

        return $this->scan->pluginClass($path, $composer, (new Manifest($manifest))->pluginClass());
    }

    /**
     * Every problem in the manifest, as errors() finds them, given the
     * class's reading instead of reading it, so it opens no file.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composer
     * @param  array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null  $reading
     *
     * @return list<string>
     */
    public function errorsGiven(array $manifest, ?array $composer, ?array $reading): array
    {
        $errors = [];

        if (($manifest['manifest'] ?? null) !== 1) {
            $errors[] = 'The manifest must say "manifest": 1.';
        }

        array_push($errors, ...$this->name($manifest, $composer));

        $code = $manifest['code'] ?? null;

        if (! is_string($code) || preg_match(self::CODE, $code) !== 1) {
            $errors[] = 'The plugin code must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter or digit.';
        }

        foreach (['label', 'description'] as $key) {
            if (! is_string($manifest[$key] ?? null) || trim($manifest[$key]) === '') {
                $errors[] = "The manifest needs a {$key}.";
            }
        }

        $version = $manifest['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            $errors[] = 'The manifest needs a version.';
        } elseif (preg_match(self::VERSION, $version) !== 1) {
            $errors[] = 'version must be a version number, such as 1.2.0.';
        }

        $read = new Manifest($manifest);

        array_push($errors, ...$this->requires($manifest));
        array_push($errors, ...$this->pluginClass($manifest, $read, $reading));
        array_push($errors, ...$this->capabilities($manifest));
        array_push($errors, ...$this->hosts($manifest, $read));
        array_push($errors, ...$this->hooks($manifest, $read));
        array_push($errors, ...$this->contributions->errors($read->contributes(), $read->version()));
        array_push($errors, ...$this->owns($manifest, $read));
        array_push($errors, ...$this->routes($manifest, $read));

        return $errors;
    }

    /**
     * The two checks never cached: the Teme versions the plugin
     * needs, and its hooks' names and capabilities, which a Teme update changes.
     *
     * @return list<string>
     */
    public function liveErrors(FoundPlugin $plugin): array
    {
        if ($plugin->manifest === null) {
            return [];
        }

        $raw = $plugin->manifest->raw();

        return [...$this->requires($raw), ...$this->hooks($raw, $plugin->manifest)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composer
     *
     * @return list<string>
     */
    private function name(array $manifest, ?array $composer): array
    {
        $name = $manifest['name'] ?? null;
        $package = is_string($composer['name'] ?? null) ? $composer['name'] : null;

        if ($package !== null && $name !== $package) {
            return ["The plugin’s name must be its Composer package’s name, \"{$package}\"."];
        }

        return is_string($name) && preg_match(self::NAME, $name) === 1 ? [] : ['The manifest needs a name.'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function requires(array $manifest): array
    {
        $requires = $manifest['requires'] ?? null;
        $constraint = is_array($requires) ? ($requires['teme'] ?? null) : null;
        $version = (string) config('teme.version');

        if (! is_string($constraint) || trim($constraint) === '') {
            return ['The manifest must say which Teme versions the plugin works with, as requires.teme.'];
        }

        $errors = [];

        try {
            if (! Semver::satisfies($version, $constraint)) {
                $errors[] = "The plugin requires Teme {$constraint}, but this is Teme {$version}.";
            }
        } catch (UnexpectedValueException) {
            $errors[] = "requires.teme \"{$constraint}\" is not a version constraint.";
        }

        if (array_diff(array_keys($requires), ['teme']) !== []) {
            $errors[] = 'requires may name only teme: a plugin can’t depend on another plugin yet.';
        }

        return $errors;
    }

    /**
     * The plugin's class, from its source's reading: it is there, it
     * implements Plugin, and its constructor asks only for what Teme offers plugins.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null  $source
     *
     * @return list<string>
     */
    private function pluginClass(array $manifest, Manifest $read, ?array $source): array
    {
        if (! is_string($manifest['plugin'] ?? null) || trim($manifest['plugin']) === '') {
            return ['plugin must name the plugin’s class.'];
        }

        $class = $read->pluginClass();

        if ($source === null) {
            return ["The class {$class} isn’t there."];
        }

        if ($source['unreadable'] !== null) {
            return [$this->unreadable($class, ...$source['unreadable'])];
        }

        if (! $source['plugin']) {
            return ["{$class} doesn’t implement Teme\\Contracts\\Plugins\\Plugin."];
        }

        $offered = array_map(strtolower(...), PluginServices::OFFERED);
        $errors = [];

        foreach ($source['parameters'] as $parameter) {
            $type = $parameter['type'];

            if (! $parameter['named'] || ! in_array(strtolower((string) $type), $offered, true)) {
                $errors[] = "{$class} asks for ".($type ?? "an untyped \${$parameter['name']}").'; a plugin may ask only for what Teme offers plugins.';
            } elseif (strtolower((string) $type) === strtolower(OutboundHttp::class) && ! in_array(Capability::Http->value, $read->capabilities(), true)) {
                $errors[] = "{$class} asks for the outbound door, but the plugin doesn’t ask for http.";
            }
        }

        return $errors;
    }

    /**
     * Why Teme can't check the plugin's class from its source alone: it
     * would have to run code to know, so it refuses rather than guess.
     */
    private function unreadable(string $class, string $kind, string $name): string
    {
        return match ($kind) {
            'parent' => "{$class} extends {$name}, which isn’t in the plugin’s own code, so Teme can’t check it without running it.",
            'interface' => "{$class} implements {$name}, which isn’t in the plugin’s own code, so Teme can’t check it without running it.",
            'trait' => "{$class} takes its constructor from {$name}, so Teme can’t check what it asks for without running it.",
            default => "{$class} is declared inside an if or a function, so Teme can’t check it without running it.",
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function capabilities(array $manifest): array
    {
        $errors = [];

        foreach ($this->listed($manifest, 'capabilities') as $capability) {
            $shown = $this->show($capability);

            if (is_string($capability) && Capability::tryFrom($capability) !== null) {
                continue;
            }

            $errors[] = in_array($capability, self::LATER, true)
                ? "Teme doesn’t offer {$shown} to plugins yet."
                : "{$shown} isn’t something a plugin can ask for.";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function hosts(array $manifest, Manifest $read): array
    {
        $errors = [];
        $hosts = $manifest['hosts'] ?? [];

        if (! is_array($hosts) || ! array_is_list($hosts) || array_filter($hosts, fn (mixed $host): bool => ! is_string($host) || preg_match(HostPattern::PATTERN, $host) !== 1) !== []) {
            $errors[] = 'hosts must be a list of host names, such as api.example.com or *.example.com.';
        }

        $http = in_array(Capability::Http->value, $read->capabilities(), true);

        if ($http && $read->hosts() === []) {
            $errors[] = 'It asks for http but names no hosts.';
        } elseif (! $http && $read->hosts() !== []) {
            $errors[] = 'It names hosts but doesn’t ask for http.';
        }

        return $errors;
    }

    /**
     * Each hook a Teme hook, by short name, whose capability the plugin asks for.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function hooks(array $manifest, Manifest $read): array
    {
        $errors = [];
        $known = self::hooksByShortName();

        foreach ($this->listed($manifest, 'hooks') as $hook) {
            $class = is_string($hook) ? ($known[$hook] ?? null) : null;

            if ($class === null) {
                $errors[] = "{$this->show($hook)} isn’t a Teme hook.";

                continue;
            }

            $capability = self::capabilityOf($class);

            if ($capability !== null && ! in_array($capability, $read->capabilities(), true)) {
                $errors[] = "{$hook} needs {$capability}, which it doesn’t ask for.";
            }
        }

        return $errors;
    }

    /**
     * Every hook of the contracts package, by its short name.
     *
     * @return array<string, class-string>
     */
    public static function hooksByShortName(): array
    {
        $hooks = [];

        foreach (HookCatalogue::HOOKS as $class) {
            $hooks[(new ReflectionClass($class))->getShortName()] = $class;
        }

        return $hooks;
    }

    /**
     * The capability a hook's contract says a listener needs, or null for none.
     *
     * @param  class-string  $hook
     */
    public static function capabilityOf(string $hook): ?string
    {
        $attribute = (new ReflectionClass($hook))->getAttributes(HookContract::class)[0] ?? null;

        return $attribute?->newInstance()->capability;
    }

    /**
     * A list key's values: each value of a list, or the one value that isn't a list.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<mixed>
     */
    private function listed(array $manifest, string $key): array
    {
        if (! array_key_exists($key, $manifest)) {
            return [];
        }

        $values = $manifest[$key];

        return is_array($values) ? array_values(array_unique($values, SORT_REGULAR)) : [$values];
    }

    /**
     * owns.tables: an object whose only key is tables, each a distinct
     * lower-case name that starts with the plugin's prefix (I-3442).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function owns(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('owns', $manifest)) {
            return [];
        }

        $owns = $manifest['owns'];

        if (! is_array($owns) || array_is_list($owns) || array_keys($owns) !== ['tables']) {
            return ['owns must be an object whose only key is tables.'];
        }

        $tables = $owns['tables'] ?? null;

        if (! is_array($tables) || ! array_is_list($tables) || count($tables) !== count(array_unique($tables, SORT_REGULAR))) {
            return ['owns.tables must be a list of distinct table names.'];
        }

        $prefix = str_replace('-', '_', $read->code()).'_';
        $errors = [];

        foreach ($tables as $table) {
            if (! is_string($table) || preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                $errors[] = 'owns.tables "'.$this->show($table).'" must be a lower-case name of letters, digits and underscores, starting with a letter.';

                continue;
            }

            if (! str_starts_with($table, $prefix)) {
                $errors[] = "owns.tables \"{$table}\" must start with the plugin’s prefix \"{$prefix}\".";
            }

            if (strlen($table) > 63) {
                $errors[] = "owns.tables \"{$table}\" must be at most 63 characters.";
            }
        }

        return $errors;
    }

    /**
     * Declared public routes: only with routes.public, and each entry held
     * to the plan's refusals (reserved paths, verifiers, collisions within the plugin).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function routes(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('routes', $manifest)) {
            return [];
        }

        $routes = $manifest['routes'];

        if (! is_array($routes) || ! array_is_list($routes)) {
            return ['routes must be a list of route objects.'];
        }

        $errors = [];
        $granted = in_array(Capability::RoutesPublic->value, $read->capabilities(), true);

        if ($routes !== [] && ! $granted) {
            $errors[] = 'It declares routes but doesn’t ask for routes.public.';
        }

        /** @var list<array<string, mixed>> $typed */
        $typed = array_values(array_filter($routes, is_array(...)));

        if (count($typed) !== count($routes)) {
            $errors[] = 'Each route must be an object.';
        }

        array_push($errors, ...$this->routeCollisions->errorsFor($read, $typed));

        return $errors;
    }

    private function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
