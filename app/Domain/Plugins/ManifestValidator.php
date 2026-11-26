<?php

/**
 * Checks a teme-plugin.json against manifest version 1, and says in plain
 * words what is wrong (I-3353). The plugin's class is read from its source,
 * never loaded, so checking a plugin runs none of its code. A plugin with any
 * problem can't be used. Duplicate codes need the other plugins, so
 * PluginRegistry checks those.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Extensions\PackagePictures;
use App\Domain\Hooks\Reference\HookCatalogue;
use App\Domain\Import\PluginData\WordPressNames;
use App\Domain\Plugins\Hooks\DeclaredHooks;
use App\Domain\Plugins\Jobs\DeclaredJobs;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\PublicRoutes\RootFile;
use App\Domain\PublicRoutes\RouteCollisions;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use Composer\Autoload\ClassLoader;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use ReflectionClass;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Cards\CardsChanged;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Redirects\RedirectWriter;
use UnexpectedValueException;

final readonly class ManifestValidator
{
    /** A plugin's Composer name, Composer's own pattern, between delimiters that aren't in it. */
    public const string NAME = '~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~';

    /** A plugin's version: three numbers, then -alpha.N, -beta.N or -rc.N for a pre-release. */
    public const string VERSION = '/^\d+\.\d+\.\d+(-(alpha|beta|rc)\.\d+)?$/';

    private const string CODE = '/^'.ThemeManifestValidator::CODE_PATTERN.'$/';

    /** The capabilities docs/architecture/plugins.md names that have no door yet. */
    private const array LATER = ['mail.send', 'site.output', 'ai'];

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
     * @return array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null, handlers?: array<string, array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null>, verifiers?: array<string, bool>}|null
     */
    public function classReading(array $manifest, string $path, ?array $composer): ?array
    {
        if (! is_string($manifest['plugin'] ?? null) || trim($manifest['plugin']) === '') {
            return null;
        }

        $reading = $this->scan->pluginClass($path, $composer, (new Manifest($manifest))->pluginClass());

        if ($reading === null) {
            return null;
        }

        // Each route's handler is built as the plugin's class is (docs/plans/plugin-sockets-1.md, A-W13), so it is read the same way.
        $handlers = [];

        foreach (is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [] as $route) {
            $handler = is_array($route) && is_string($route['handler'] ?? null) ? ltrim($route['handler'], '\\') : '';

            if ($handler !== '' && ! array_key_exists($handler, $handlers)) {
                $handlers[$handler] = $this->scan->pluginClass($path, $composer, $handler);
            }
        }

        // A custom verifier's class is named in the manifest too, and must be in the plugin's code.
        $verifiers = [];

        foreach (is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [] as $route) {
            $verifier = is_array($route) && is_array($route['verify'] ?? null) && is_string($route['verify']['class'] ?? null) ? ltrim($route['verify']['class'], '\\') : '';

            if ($verifier !== '' && ! array_key_exists($verifier, $verifiers)) {
                $verifiers[$verifier] = $this->scan->pluginClass($path, $composer, $verifier) !== null;
            }
        }

        return [...$reading, 'handlers' => $handlers, 'verifiers' => $verifiers];
    }

    /**
     * Every problem in the manifest, as errors() finds them, given the
     * class's reading instead of reading it, so it opens no file.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $composer
     * @param  array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null, handlers?: array<string, array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null>, verifiers?: array<string, bool>}|null  $reading
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
        array_push($errors, ...$this->hostsFrom($manifest, $read));
        array_push($errors, ...$this->hooks($manifest, $read));
        array_push($errors, ...$this->contributions->errors($read->contributes(), $read->version(), $read->capabilities()));
        array_push($errors, ...$this->feedLinks($read));
        array_push($errors, ...$this->columns($read));
        array_push($errors, ...$this->aiTasks($read));
        array_push($errors, ...$this->notices($read));
        array_push($errors, ...$this->owns($manifest, $read));
        array_push($errors, ...$this->jobs($manifest, $read));
        array_push($errors, ...$this->schedules($manifest, $read));
        array_push($errors, ...$this->routes($manifest, $read));
        array_push($errors, ...$this->rootFiles($manifest, $read));
        array_push($errors, ...$this->handlers($read, $reading));
        array_push($errors, ...$this->imports($manifest, $read));
        array_push($errors, ...$this->httpsAddress($manifest, 'author_url'));
        array_push($errors, ...$this->httpsAddress($manifest, 'homepage'));
        array_push($errors, ...PackagePictures::errors($manifest, 'plugin'));

        return $errors;
    }

    /**
     * Columns describe content, including drafts, so content.read is required.
     *
     * @return list<string>
     */
    private function columns(Manifest $read): array
    {
        $columns = $read->contributes()['columns'] ?? null;

        if (! is_array($columns) || $columns === []) {
            return [];
        }

        if (! in_array(Capability::ContentRead->value, $read->capabilities(), true)) {
            return ['It adds columns to the content lists, but the plugin doesn’t ask for content.read.'];
        }

        return [];
    }

    /**
     * AI tasks spend the site's AI budget, so ai is required.
     *
     * @return list<string>
     */
    private function aiTasks(Manifest $read): array
    {
        $tasks = $read->contributes()['aiTasks'] ?? null;

        if (! is_array($tasks) || $tasks === [] || in_array(Capability::Ai->value, $read->capabilities(), true)) {
            return [];
        }

        return [__('It declares AI tasks, but the plugin doesn’t ask for ai.')];
    }

    /**
     * A plugin's own kinds of notice are sent only with notices.send (docs/plans/plugin-sockets-3.md).
     *
     * @return list<string>
     */
    private function notices(Manifest $read): array
    {
        $notices = $read->contributes()['notices'] ?? null;

        if (! is_array($notices) || $notices === [] || in_array(Capability::NoticesSend->value, $read->capabilities(), true)) {
            return [];
        }

        return [__('It declares kinds of notice, but the plugin doesn’t ask for notices.send.')];
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

        if (array_diff(array_keys($requires), ['teme', 'plugins']) !== []) {
            $errors[] = __('requires may name only teme and plugins.');
        }

        if (array_key_exists('plugins', $requires)) {
            array_push($errors, ...$this->requiredPlugins($requires['plugins'], is_string($manifest['name'] ?? null) ? $manifest['name'] : ''));
        }

        return $errors;
    }

    /**
     * requires.plugins: an object of other plugins' Composer names and the
     * versions of each it works with, at most Dependencies::MOST
     * (docs/plans/plugin-sockets-3.md).
     *
     * @return list<string>
     */
    private function requiredPlugins(mixed $plugins, string $own): array
    {
        if (! is_array($plugins) || ($plugins !== [] && array_is_list($plugins))) {
            return [__('requires.plugins must be an object of plugin names and the versions each works with.')];
        }

        $errors = count($plugins) > Dependencies::MOST ? [__('requires.plugins may name at most 5 plugins.')] : [];
        $parser = new VersionParser;

        foreach ($plugins as $name => $constraint) {
            $name = (string) $name;

            if (preg_match(self::NAME, $name) !== 1 || $name === $own) {
                $errors[] = __('requires.plugins names ":name", which isn’t another plugin’s Composer name.', ['name' => $name]);

                continue;
            }

            try {
                if (! is_string($constraint) || trim($constraint) === '') {
                    throw new UnexpectedValueException('empty');
                }

                $parser->parseConstraints($constraint);
            } catch (UnexpectedValueException) {
                $errors[] = __('requires.plugins ":name" needs a version constraint, such as ^1.0.', ['name' => $name]);
            }
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

        return $this->asks($class, $source['parameters'], $read);
    }

    /**
     * What a class Teme builds for the plugin asks of its constructor: only
     * what Teme offers plugins, and each door only with its capability.
     *
     * @param  list<array{name: string, type: string|null, named: bool}>  $parameters
     *
     * @return list<string>
     */
    private function asks(string $class, array $parameters, Manifest $read): array
    {
        $offered = array_map(strtolower(...), PluginServices::OFFERED);
        $errors = [];

        foreach ($parameters as $parameter) {
            $type = $parameter['type'];

            if (! $parameter['named'] || ! in_array(strtolower((string) $type), $offered, true)) {
                $errors[] = "{$class} asks for ".($type ?? "an untyped \${$parameter['name']}").'; a plugin may ask only for what Teme offers plugins.';
            } elseif (strtolower((string) $type) === strtolower(OutboundHttp::class) && ! in_array(Capability::Http->value, $read->capabilities(), true)) {
                $errors[] = "{$class} asks for the outbound door, but the plugin doesn’t ask for http.";
            } elseif (strtolower((string) $type) === strtolower(ContentWriter::class) && ! in_array(Capability::ContentWrite->value, $read->capabilities(), true)) {
                $errors[] = "{$class} asks for the content door, but the plugin doesn’t ask for content.write.";
            } elseif (strtolower((string) $type) === strtolower(MediaWriter::class) && ! in_array(Capability::MediaWrite->value, $read->capabilities(), true)) {
                $errors[] = "{$class} asks for the media door, but the plugin doesn’t ask for media.write.";
            } elseif (strtolower((string) $type) === strtolower(Jobs::class) && ! in_array(Capability::Jobs->value, $read->capabilities(), true)) {
                $errors[] = "{$class} asks for jobs, but the plugin doesn’t ask for jobs.";
            } elseif (strtolower((string) $type) === strtolower(InsertionsChanged::class) && ! self::inserts($read)) {
                $errors[] = "{$class} asks for InsertionsChanged, but the plugin doesn’t ask for content.insert.";
            } elseif (strtolower((string) $type) === strtolower(CardsChanged::class) && ! in_array(Capability::PagesInsert->value, $read->capabilities(), true)) {
                $errors[] = __(':class asks for CardsChanged, but the plugin doesn’t ask for pages.insert.', ['class' => $class]);
            } elseif (strtolower((string) $type) === strtolower(InlineScripts::class) && ! self::inserts($read)) {
                $errors[] = "{$class} asks for InlineScripts, but the plugin doesn’t ask for content.insert or pages.insert.";
            } elseif (strtolower((string) $type) === strtolower(SiteAi::class) && ! in_array(Capability::Ai->value, $read->capabilities(), true)) {
                $errors[] = __(':class asks for SiteAi, but the plugin doesn’t ask for ai.', ['class' => $class]);
            } elseif (strtolower((string) $type) === strtolower(Notices::class) && ! in_array(Capability::NoticesSend->value, $read->capabilities(), true)) {
                $errors[] = __(':class asks for Notices, but the plugin doesn’t ask for notices.send.', ['class' => $class]);
            } elseif (strtolower((string) $type) === strtolower(RedirectWriter::class) && ! in_array(Capability::Redirects->value, $read->capabilities(), true)) {
                $errors[] = __(':class asks for RedirectWriter, but the plugin doesn’t ask for redirects.', ['class' => $class]);
            } elseif (strtolower((string) $type) === strtolower(HookSender::class) && DeclaredHooks::of($read) === []) {
                $errors[] = __(':class asks for HookSender, but the plugin declares no hooks under contributes.hooks.', ['class' => $class]);
            }
        }

        return $errors;
    }

    /**
     * Each route's handler, from its source's reading, asks only for what
     * the plugin's class may (docs/plans/plugin-sockets-1.md, A-W13). A
     * handler or verifier the plugin's own code doesn't declare is refused
     * unless Composer's autoloader can load it, as it can for a class of a
     * package the plugin depends on: those run today, so they stay allowed.
     *
     * @param  array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null, handlers?: array<string, array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null>, verifiers?: array<string, bool>}|null  $reading
     *
     * @return list<string>
     */
    private function handlers(Manifest $read, ?array $reading): array
    {
        $errors = [];

        // A class a route names that the plugin's own code doesn't declare: said now, with the key that names it.
        foreach ($read->routes() as $number => $route) {
            $handler = is_string($route['handler'] ?? null) ? ltrim($route['handler'], '\\') : '';
            $verifier = is_array($route['verify'] ?? null) && is_string($route['verify']['class'] ?? null) ? ltrim($route['verify']['class'], '\\') : '';

            if ($handler !== '' && $reading !== null && array_key_exists($handler, $reading['handlers'] ?? []) && $reading['handlers'][$handler] === null && ! self::loadable($handler)) {
                $errors[] = __('routes.:number.handler names :class, which isn’t in the plugin’s code.', ['number' => (string) $number, 'class' => $handler]);
            }

            if ($verifier !== '' && $reading !== null && ($reading['verifiers'][$verifier] ?? true) === false && ! self::loadable($verifier)) {
                $errors[] = __('routes.:number.verify.class names :class, which isn’t in the plugin’s code.', ['number' => (string) $number, 'class' => $verifier]);
            }
        }

        foreach ($reading['handlers'] ?? [] as $class => $handler) {
            if ($handler === null) {
                continue;
            }

            if ($handler['unreadable'] !== null && $handler['unreadable'][0] !== 'interface') {
                $errors[] = $this->unreadable($class, ...$handler['unreadable']);

                continue;
            }

            array_push($errors, ...$this->asks($class, $handler['parameters'], $read));
        }

        return $errors;
    }

    /**
     * Whether Composer's autoloader knows where a class is, without loading it (so no code runs).
     */
    private static function loadable(string $class): bool
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if ($loader->findFile($class) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the plugin asks to insert content, in articles or on every page.
     */
    private static function inserts(Manifest $read): bool
    {
        return in_array(Capability::ContentInsert->value, $read->capabilities(), true) || in_array(Capability::PagesInsert->value, $read->capabilities(), true);
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
     * Links in the feeds only with feeds.links (docs/plans/plugin-sockets-1.md, A3).
     *
     * @return list<string>
     */
    private function feedLinks(Manifest $read): array
    {
        $links = $read->contributes()['feedLinks'] ?? [];

        return $links !== [] && ! in_array(Capability::FeedsLinks->value, $read->capabilities(), true)
            ? [__('It adds links to the feeds, but the plugin doesn’t ask for feeds.links.')]
            : [];
    }

    /**
     * hostsFrom: at most three of the plugin's own url or urls settings, and
     * only with http (docs/plans/plugin-sockets-1.md, A2).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function hostsFrom(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('hostsFrom', $manifest)) {
            return [];
        }

        $keys = $manifest['hostsFrom'];

        if (! is_array($keys) || ! array_is_list($keys) || count($keys) > SettingHosts::MOST_SETTINGS) {
            return [__('hostsFrom must be a list of at most 3 of its url or urls settings.')];
        }

        $errors = [];
        $settings = SettingHosts::urlSettings($read);

        foreach ($keys as $key) {
            if (! is_string($key) || ! array_key_exists($key, $settings)) {
                $errors[] = __('hostsFrom names :key, which is not one of its url or urls settings.', ['key' => $this->show($key)]);
            }
        }

        if ($keys !== [] && ! in_array(Capability::Http->value, $read->capabilities(), true)) {
            $errors[] = __('It takes hosts from its settings, but the plugin doesn’t ask for http.');
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
            // Another plugin's own hook: Dependencies checks it against that plugin.
            if (is_string($hook) && preg_match(DeclaredHooks::FULL_NAME, $hook) === 1) {
                continue;
            }

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
     * jobs: a list of declarations, each with a unique key, when the plugin
     * asks for the jobs capability (I-3445).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function jobs(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('jobs', $manifest) && ! array_key_exists('schedules', $manifest)) {
            return [];
        }

        $errors = [];
        $jobs = $manifest['jobs'] ?? null;
        $keys = [];

        if ($jobs !== null && (! is_array($jobs) || ! array_is_list($jobs))) {
            $errors[] = 'jobs must be a list of job declarations.';
            $jobs = [];
        }

        foreach (is_array($jobs) ? $jobs : [] as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'jobs must be a list of job declarations.';

                continue;
            }

            $key = $entry['key'] ?? null;

            if (! is_string($key) || preg_match(DeclaredJobs::KEY, $key) !== 1) {
                $errors[] = 'jobs "'.$this->show($key).'" must be a lower-case key of letters, digits and hyphens, at most 48 characters, starting with a letter.';

                continue;
            }

            if (isset($keys[$key])) {
                $errors[] = "jobs key \"{$key}\" is listed more than once.";
            }

            $keys[$key] = true;
            $label = $entry['label'] ?? null;

            if (! is_string($label) || trim($label) === '' || strlen($label) > 80) {
                $errors[] = "jobs \"{$key}\" needs a label of 1 to 80 characters.";
            }

            if (array_key_exists('long', $entry) && ! is_bool($entry['long'])) {
                $errors[] = "jobs \"{$key}\" long must be true or false.";
            }

            if (array_key_exists('tries', $entry) && (! is_int($entry['tries']) || $entry['tries'] < 1 || $entry['tries'] > 5)) {
                $errors[] = "jobs \"{$key}\" tries must be a whole number from 1 to 5.";
            }

            if (array_key_exists('backoff', $entry) && ! $this->backoffOk($entry['backoff'])) {
                $errors[] = "jobs \"{$key}\" backoff must be a list of 1 to 5 whole numbers from 1 to 3600.";
            }
        }

        if (($jobs !== null && $jobs !== []) || array_key_exists('schedules', $manifest)) {
            if (! in_array(Capability::Jobs->value, $read->capabilities(), true)) {
                $errors[] = 'It declares jobs but doesn’t ask for jobs.';
            }
        }

        return $errors;
    }

    /**
     * schedules: each names a declared job and a fixed interval (I-3445).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function schedules(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('schedules', $manifest)) {
            return [];
        }

        $schedules = $manifest['schedules'];

        if (! is_array($schedules) || ! array_is_list($schedules)) {
            return ['schedules must be a list of schedule declarations.'];
        }

        $keys = [];

        foreach ($manifest['jobs'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null) && preg_match(DeclaredJobs::KEY, $entry['key']) === 1) {
                $keys[$entry['key']] = true;
            }
        }

        $errors = [];
        $settings = null;

        foreach ($schedules as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'schedules must be a list of schedule declarations.';

                continue;
            }

            $job = $entry['job'] ?? null;

            if (! is_string($job) || ! isset($keys[$job])) {
                $errors[] = 'schedules names job "'.$this->show($job).'", which isn’t declared.';
            }

            $every = $entry['every'] ?? null;

            if (! is_string($every) || ! in_array($every, DeclaredJobs::EVERY, true)) {
                $errors[] = 'schedules every must be one of minute, five-minutes, fifteen-minutes, hour, day, week.';
            }

            $errors = [...$errors, ...$this->scheduleSettings($entry, $settings ??= self::declaredSettings($manifest))];
        }

        return $errors;
    }

    /**
     * A schedule's own settings (docs/plans/plugin-sockets-3.md, scheduler
     * port): `enabledBy` names one of the plugin's boolean settings, which
     * switches it on and off, and `everyFrom` a select setting whose options
     * are all intervals a schedule may take.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  array<string, array<array-key, mixed>>  $settings
     *
     * @return list<string>
     */
    private function scheduleSettings(array $entry, array $settings): array
    {
        $errors = [];

        if (array_key_exists('enabledBy', $entry)) {
            $key = $entry['enabledBy'];

            if (! is_string($key) || ($settings[$key]['type'] ?? null) !== 'boolean') {
                $errors[] = __('schedules enabledBy names ":key", which isn’t one of the plugin’s boolean settings.', ['key' => $this->show($key)]);
            }
        }

        if (array_key_exists('everyFrom', $entry)) {
            $key = $entry['everyFrom'];
            $options = is_string($key) ? ($settings[$key]['options'] ?? null) : null;

            if (! is_string($key) || ($settings[$key]['type'] ?? null) !== 'select' || ! is_array($options) || $options === []
                || array_diff(array_map(strval(...), array_keys($options)), DeclaredJobs::EVERY) !== []) {
                $errors[] = __('schedules everyFrom names ":key", which isn’t a select setting whose options are all minute, five-minutes, fifteen-minutes, hour, day or week.', ['key' => $this->show($key)]);
            }
        }

        return $errors;
    }

    /**
     * The settings the manifest declares under `contributes.settings`, by key.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return array<string, array<array-key, mixed>>
     */
    private static function declaredSettings(array $manifest): array
    {
        $contributes = $manifest['contributes'] ?? null;
        $declared = is_array($contributes) && is_array($contributes['settings'] ?? null) ? $contributes['settings'] : [];
        $settings = [];

        foreach ($declared as $setting) {
            if (is_array($setting) && is_string($setting['key'] ?? null)) {
                $settings[$setting['key']] = $setting;
            }
        }

        return $settings;
    }

    private function backoffOk(mixed $backoff): bool
    {
        if (! is_array($backoff) || ! array_is_list($backoff) || $backoff === [] || count($backoff) > 5) {
            return false;
        }

        foreach ($backoff as $seconds) {
            if (! is_int($seconds) || $seconds < 1 || $seconds > 3600) {
                return false;
            }
        }

        return true;
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

    /**
     * Root files (docs/guides/developers/plugins/root-files.md): only with routes.public, at most
     * RootFile::MOST, each a name and a body, literal or filled from one of
     * the plugin's own text settings, and no literal name of core's.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function rootFiles(array $manifest, Manifest $read): array
    {
        if (! array_key_exists('rootFiles', $manifest)) {
            return [];
        }

        $files = $manifest['rootFiles'];

        if (! is_array($files) || ! array_is_list($files)) {
            return ['rootFiles must be a list of root file objects.'];
        }

        $errors = [];

        if ($files !== [] && ! in_array(Capability::RoutesPublic->value, $read->capabilities(), true)) {
            $errors[] = 'It declares root files but doesn’t ask for routes.public.';
        }

        if (count($files) > RootFile::MOST) {
            $errors[] = 'It declares '.count($files).' root files; a plugin may declare at most '.RootFile::MOST.'.';
        }

        $texts = [];

        foreach ($read->contributes()['settings'] ?? [] as $setting) {
            if (is_array($setting) && ($setting['type'] ?? null) === 'text' && is_string($setting['key'] ?? null)) {
                $texts[] = $setting['key'];
            }
        }

        $ids = [];
        $names = [];

        foreach ($files as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['name'] ?? null) || ! is_string($entry['body'] ?? null)) {
                $errors[] = 'Each root file must be an object with an id, a name and a body.';

                continue;
            }

            $file = new RootFile($read->name(), $entry['id'], $entry['name'], $entry['body']);

            if (preg_match('/^[a-z][a-z0-9_-]*$/', $file->id) !== 1) {
                $errors[] = 'Each root file needs an id of lower-case letters, digits, hyphens and underscores.';
            } elseif (in_array($file->id, $ids, true)) {
                $errors[] = "rootFiles id \"{$file->id}\" is listed more than once.";
            }

            $ids[] = $file->id;
            $nameSetting = $file->nameSetting();

            if ($nameSetting !== null) {
                if (! in_array($nameSetting, $texts, true)) {
                    $errors[] = "rootFiles \"{$file->id}\" takes its name from {$nameSetting}, which isn’t one of its text settings.";
                }
            } elseif (preg_match(RootFile::NAME, $file->name) !== 1) {
                $errors[] = "rootFiles \"{$file->id}\" name must be 8 to 128 letters, digits and hyphens, then .txt, or {setting}.txt.";
            } elseif (in_array($file->name, $names, true)) {
                $errors[] = "rootFiles name {$file->name} is listed more than once.";
            } else {
                $names[] = $file->name;
            }

            $bodySetting = $file->bodySetting();

            if ($bodySetting !== null) {
                if (! in_array($bodySetting, $texts, true)) {
                    $errors[] = "rootFiles \"{$file->id}\" takes its body from {$bodySetting}, which isn’t one of its text settings.";
                }
            } elseif ($file->body === '' || strlen($file->body) > RootFile::MOST_BYTES) {
                $errors[] = "rootFiles \"{$file->id}\" body must be 1 to ".RootFile::MOST_BYTES.' bytes, or {setting}.';
            }
        }

        array_push($errors, ...$this->routeCollisions->rootFileErrors($read));

        return $errors;
    }

    /**
     * imports: an object of what the plugin brings across from WordPress,
     * each name of a WordPress option or meta key's shape, never one Teme
     * never sends, at most WordPressNames::MOST in all, and only with
     * import.wordpress (docs/architecture/plugins.md).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function imports(array $manifest, Manifest $read): array
    {
        $asks = in_array(Capability::ImportWordPress->value, $read->capabilities(), true);

        if (! array_key_exists('imports', $manifest)) {
            return $asks ? ['It asks for import.wordpress but declares nothing to import.'] : [];
        }

        $imports = $manifest['imports'];

        if (! is_array($imports) || ($imports !== [] && array_is_list($imports))) {
            return ['imports must be an object: what the plugin brings across from WordPress.'];
        }

        $errors = $asks ? [] : ['It declares imports but doesn’t ask for import.wordpress.'];

        foreach (array_diff(array_keys($imports), WordPressNames::KEYS) as $key) {
            $errors[] = 'imports may hold only importer, options, postTypes, userMeta and termMeta: "'.$key.'" isn’t one of them.';
        }

        if (! is_string($imports['importer'] ?? null) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $imports['importer']) !== 1) {
            $errors[] = 'imports.importer must name the plugin’s importer class.';
        }

        $count = 0;

        foreach (['options', 'userMeta', 'termMeta'] as $key) {
            if (array_key_exists($key, $imports)) {
                array_push($errors, ...$this->importNames("imports.{$key}", $imports[$key], $count));
            }
        }

        $postTypes = $imports['postTypes'] ?? [];

        if (! is_array($postTypes) || ($postTypes !== [] && array_is_list($postTypes))) {
            $errors[] = 'imports.postTypes must be an object of post types, each with a list of its meta keys.';
            $postTypes = [];
        }

        foreach ($postTypes as $type => $meta) {
            $count++;

            if (preg_match(WordPressNames::POST_TYPE, (string) $type) !== 1) {
                $errors[] = "imports.postTypes \"{$type}\" isn’t the shape of a WordPress post type.";
            }

            array_push($errors, ...$this->importNames("imports.postTypes.{$type}", $meta, $count));
        }

        if ($count > WordPressNames::MOST) {
            $errors[] = "imports declares {$count} names; declare only what the plugin needs, at most ".WordPressNames::MOST.'.';
        }

        return $errors;
    }

    /**
     * One list of names under imports, each counted.
     *
     * @return list<string>
     */
    private function importNames(string $where, mixed $names, int &$count): array
    {
        if (! is_array($names) || ! array_is_list($names)) {
            return ["{$where} must be a list of names."];
        }

        $errors = [];

        foreach ($names as $name) {
            $count++;

            if (! is_string($name) || preg_match(WordPressNames::PATTERN, $name) !== 1) {
                $errors[] = "{$where} \"{$this->show($name)}\" isn’t the shape of a WordPress option or meta key.";
            } elseif (WordPressNames::neverSent($name)) {
                $errors[] = "{$where} names {$name}, which Teme never brings across from WordPress.";
            }
        }

        return $errors;
    }

    /**
     * An optional https address of at most 2000 characters, naming the key when wrong.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return list<string>
     */
    private function httpsAddress(array $manifest, string $key): array
    {
        if (! array_key_exists($key, $manifest)) {
            return [];
        }

        $value = $manifest[$key];

        if (! is_string($value) || strlen($value) > 2000 || ! str_starts_with(strtolower($value), 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ["{$key} must be an https address of at most 2000 characters."];
        }

        return [];
    }

    private function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
