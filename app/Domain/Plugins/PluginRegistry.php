<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use Composer\InstalledVersions;
use FilesystemIterator;
use Illuminate\Support\Facades\Context;
use JsonException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ValueError;

/**
 * Every plugin on disk (I-3353): Composer packages of type `teme-plugin`,
 * found through Composer's runtime data, then the folders in
 * `config('teme.plugin_paths')`. What each one's manifest, class and code
 * say is kept in the plugin cache under an identity that changes when the
 * plugin does, and read again when it differs; the Teme version and the
 * hooks are checked on every read. It only reads files; it never runs a
 * plugin's code, and a plugin it can't read, or a cache it can't load,
 * never stops it.
 *
 * @phpstan-type Reading array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}
 * @phpstan-type Entry array{path: string, package: string|null, identity: string, manifest: array<string, mixed>|null, composer: array<string, mixed>|null, reading: Reading|null, code: list<string>, migrations: list<string>}
 */
final class PluginRegistry
{
    /**
     * What the cache file holds, so one written in another shape reads as no
     * cache. It carries no Teme version: the image builds it before the version
     * is set (so a new version rebuilds no layer), and the Teme version and the
     * hooks are checked on every read anyway. A deployment that changes Teme
     * runs `teme:plugins:cache` again, as `optimize` does.
     */
    private const int FORMAT = 4;

    /** @var array<string, array<string, FoundPlugin>> each discovery, by the settings it was made from */
    private array $memo = [];

    public function __construct(
        private readonly ManifestValidator $validator,
        private readonly CodeScan $scan,
    ) {}

    /**
     * Every plugin, keyed by its Composer name. A plugin whose name or code
     * an earlier one already took is listed under its folder's path instead.
     *
     * @return array<string, FoundPlugin>
     */
    public function all(): array
    {
        $paths = $this->configuredPaths();
        $cache = (string) config('teme.plugin_cache');
        $key = json_encode([$paths, $cache, (string) config('teme.version')], JSON_THROW_ON_ERROR);

        if (! isset($this->memo[$key])) {
            $plugins = [];

            foreach ($this->entries($cache, $paths) as $entry) {
                $this->add($plugins, $this->found($entry));
            }

            $this->memo[$key] = $plugins;
        }

        return $this->memo[$key];
    }

    /**
     * Whether any plugin may be installed, from Composer's runtime data and
     * the configuration alone, opening no plugin file and asking no database.
     */
    public function anyInstalled(): bool
    {
        $paths = config('teme.plugin_paths');

        return InstalledVersions::getInstalledPackagesByType('teme-plugin') !== [] || (is_array($paths) && $paths !== []);
    }

    /**
     * The plugin with this Composer name, usable or not, or null when none has it.
     */
    public function find(string $name): ?FoundPlugin
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * This plugin read again from its folder, never from the cache: its
     * manifest checked and its code scanned now, as enabling it needs.
     */
    public function fresh(FoundPlugin $plugin): FoundPlugin
    {
        $package = ($this->installedPackages()[$plugin->name] ?? null) === $plugin->path ? $plugin->name : null;

        return $this->found($this->entry($plugin->path, $package, ''));
    }

    /**
     * Forgets every discovery, so the next read looks again.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Reads every plugin now and writes the cache whole or not at all: a
     * file beside it, checked, then renamed over it, so a full disk leaves
     * the old cache in place. Returns how many plugins it holds, or null
     * when it couldn't be written.
     */
    public function writeCache(): ?int
    {
        $path = (string) config('teme.plugin_cache');
        $entries = [];

        foreach ($this->candidates($this->configuredPaths()) as [$folder, $package]) {
            $entries[] = $this->entry($folder, $package, $this->identity($folder, $package));
        }

        if ($path === '' || ! $this->write($path, $entries)) {
            return null;
        }

        $this->flush();

        return count($entries);
    }

    /**
     * Each plugin's entry: the cached one while its identity still matches,
     * else read now for this process alone. Only `teme:plugins:cache` writes
     * the cache: a request, a job or the scheduler never writes code (I-211).
     *
     * @param  list<string>  $paths
     *
     * @return list<Entry>
     */
    private function entries(string $cache, array $paths): array
    {
        $cached = $cache === '' ? null : $this->readCache($cache);
        $entries = [];

        foreach ($this->candidates($paths) as [$folder, $package]) {
            $identity = $cache === '' ? '' : $this->identity($folder, $package);
            $entry = $cached[($package ?? '').'|'.$folder] ?? null;

            if ($entry === null || $identity === '' || $entry['identity'] !== $identity) {
                $entry = $this->entry($folder, $package, $identity);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Every folder to look in, with Composer's name for an installed
     * package or null for a plugin_paths folder: Composer's first.
     *
     * @param  list<string>  $paths
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function candidates(array $paths): array
    {
        $candidates = [];

        foreach ($this->installedPackages() as $name => $folder) {
            $candidates[] = [$folder, $name];
        }

        foreach ($paths as $folder) {
            $candidates[] = [$folder, null];
        }

        return $candidates;
    }

    /**
     * What changes when the plugin does, read without opening any of its
     * files: for a Composer package, the commit and version Composer
     * installed and the manifest's time and size; for a plugin_paths
     * folder, the time and size of every file in it but its top-level
     * vendor, tests and node_modules folders. Empty when it can't be told,
     * which is never taken as a match.
     */
    private function identity(string $path, ?string $package): string
    {
        try {
            $manifest = $path.'/teme-plugin.json';
            clearstatcache(true, $manifest);
            $stat = [@filemtime($manifest), @filesize($manifest)];

            if ($package !== null) {
                return json_encode([InstalledVersions::getReference($package), InstalledVersions::getVersion($package), $stat], JSON_THROW_ON_ERROR);
            }

            $files = [];
            clearstatcache();

            $top = fn (SplFileInfo $file): bool => $file->getPath() !== $path || ! in_array(strtolower($file->getFilename()), ['vendor', 'tests', 'node_modules'], true);

            foreach (new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), $top)) as $file) {
                if ($file instanceof SplFileInfo && ($file->isFile() || $file->isLink())) {
                    $files[$file->getPathname()] = [$file->isLink() ? $file->getLinkTarget() : null, $file->getMTime(), $file->getSize()];
                }

                // A folder too large to tell is read again each time, and its scan refuses it.
                if (count($files) > 10 * CodeScan::MOST_FILES) {
                    return '';
                }
            }

            ksort($files);

            return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The plugin in this folder, read and scanned now. $package is
     * Composer's name for an installed package, null for a plugin_paths folder.
     *
     * @return Entry
     */
    private function entry(string $path, ?string $package, string $identity): array
    {
        try {
            [$raw, $problem] = $this->readManifest($path);
            $composer = $this->composerJson($path, $package);

            return [
                'path' => $path,
                'package' => $package,
                'identity' => $identity,
                'manifest' => $raw,
                'composer' => $composer,
                'reading' => $raw === null ? null : $this->validator->classReading($raw, $path, $composer),
                'code' => $problem === null ? $this->scan->problems($path, $composer) : [$problem],
                'migrations' => $this->migrationNames($path),
            ];
        } catch (Throwable $failure) {
            // A folder or file that can't be read stops this plugin, never the others, and is read again next time.
            Context::scope(fn () => report($failure), ['plugin' => $package ?? $path]);

            return ['path' => $path, 'package' => $package, 'identity' => '', 'manifest' => null, 'composer' => null, 'reading' => null, 'code' => [$this->unreadable($failure)], 'migrations' => []];
        }
    }

    /**
     * The plugin's migration file names without .php, sorted by name.
     *
     * @return list<string>
     */
    private function migrationNames(string $path): array
    {
        $folder = $path.'/database/migrations';

        if (! is_dir($folder)) {
            return [];
        }

        $names = [];

        foreach (scandir($folder) ?: [] as $file) {
            if (str_ends_with($file, '.php') && is_file($folder.'/'.$file)) {
                $names[] = substr($file, 0, -4);
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Why the plugin's files couldn't be read, in plain words: where, by its
     * path inside the plugin, and one of three reasons. PHP's own message,
     * with the server's paths, goes only to the log.
     */
    private function unreadable(Throwable $failure): string
    {
        $why = match (true) {
            $failure instanceof PluginFileUnreadable => $failure->why,
            $failure instanceof JsonException, $failure instanceof ValueError => PluginFileUnreadable::VALID,
            default => PluginFileUnreadable::OPENED,
        };
        $place = $failure instanceof PluginFileUnreadable ? " ({$failure->place})" : '';

        return match ($why) {
            PluginFileUnreadable::LARGE => "Teme couldn’t read the plugin’s files{$place}: they’re too large.",
            PluginFileUnreadable::VALID => "Teme couldn’t read the plugin’s files{$place}: they aren’t valid.",
            default => "Teme couldn’t read the plugin’s files{$place}: they can’t be opened.",
        };
    }

    /**
     * @param  array<string, FoundPlugin>  $plugins
     */
    private function add(array &$plugins, FoundPlugin $plugin): void
    {
        $taken = null;

        foreach ($plugins as $earlier) {
            if ($earlier->code === $plugin->code) {
                $taken = $earlier;

                break;
            }
        }

        if ($taken === null && ! isset($plugins[$plugin->name])) {
            $plugins[$plugin->name] = $plugin;

            return;
        }

        $problems = $taken === null ? $plugin->problems : [...$plugin->problems, "The plugin code \"{$plugin->code}\" is already used by {$taken->name}."];
        $plugins[$plugin->path] = new FoundPlugin($plugin->name, $plugin->code, $plugin->path, $plugin->manifest, $problems, $plugin->migrations);
    }

    /**
     * The cache file's entries, by package and folder, or null when there
     * is none or it can't be used: cut short, written by an older Teme or
     * for another Teme version, not what writeCache() writes, or code that
     * throws. Teme then reads the plugins without it.
     *
     * @return array<string, Entry>|null
     */
    private function readCache(string $cache): ?array
    {
        if (! is_file($cache)) {
            return null;
        }

        try {
            $cached = (static fn (string $file): mixed => require $file)($cache);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($cached) || ($cached['format'] ?? null) !== self::FORMAT || ! is_array($cached['plugins'] ?? null)) {
            return null;
        }

        $entries = [];

        foreach ($cached['plugins'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! array_key_exists('package', $entry) || ! (is_string($entry['package']) || $entry['package'] === null)
                || ! is_string($entry['identity'] ?? null) || ! array_key_exists('manifest', $entry) || ! (is_array($entry['manifest']) || $entry['manifest'] === null)
                || ! array_key_exists('composer', $entry) || ! (is_array($entry['composer']) || $entry['composer'] === null)
                || ! array_key_exists('reading', $entry) || ! (is_array($entry['reading']) || $entry['reading'] === null)
                || ! is_array($entry['code'] ?? null) || ! array_is_list($entry['code'])
                || ! is_array($entry['migrations'] ?? null) || ! array_is_list($entry['migrations'])) {
                return null;
            }

            /** @var Entry $entry */
            $entries[($entry['package'] ?? '').'|'.$entry['path']] = $entry;
        }

        return $entries;
    }

    /**
     * Writes these entries to the cache whole or not at all, through a file
     * beside it renamed over it once checked. Whether it was written.
     *
     * @param  list<Entry>  $entries
     */
    private function write(string $path, array $entries): bool
    {
        $contents = '<?php return '.var_export(['format' => self::FORMAT, 'plugins' => $entries], true).';'.PHP_EOL;
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true)) {
                return false;
            }

            if (@file_put_contents($temporary, $contents) !== strlen($contents) || ! @rename($temporary, $path)) {
                @unlink($temporary);

                return false;
            }
        } catch (Throwable) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Every installed package of type `teme-plugin`, name => install path,
     * each once (Composer reports a package once per autoloader that has it).
     *
     * @return array<string, string>
     */
    private function installedPackages(): array
    {
        $packages = [];

        foreach (array_unique(InstalledVersions::getInstalledPackagesByType('teme-plugin')) as $name) {
            $path = InstalledVersions::getInstallPath($name);

            // A metapackage has no install path.
            if ($path !== null) {
                $packages[$name] = realpath($path) ?: $path;
            }
        }

        return $packages;
    }

    /**
     * A found plugin from its entry: the manifest checked now, with the
     * class's reading the entry keeps, then the code's problems.
     *
     * @param  Entry  $entry
     */
    private function found(array $entry): FoundPlugin
    {
        $path = $entry['path'];
        $composer = $entry['composer'];
        $fallback = $entry['package'] ?? (is_string($composer['name'] ?? null) ? $composer['name'] : $path);
        $raw = $entry['manifest'];

        if ($raw === null) {
            return new FoundPlugin($fallback, basename($path), $path, null, $entry['code'], $entry['migrations']);
        }

        $manifest = new Manifest($raw);

        return new FoundPlugin(
            $manifest->name() !== '' ? $manifest->name() : $fallback,
            $manifest->code() !== '' ? $manifest->code() : basename($path),
            $path,
            $manifest,
            [...$this->validator->errorsGiven($raw, $composer, $entry['reading']), ...$entry['code']],
            $entry['migrations'],
        );
    }

    /**
     * The folder's teme-plugin.json, or null and why it can't be read.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function readManifest(string $path): array
    {
        $file = $path.'/teme-plugin.json';

        try {
            $raw = is_file($file) ? json_decode(PluginFileUnreadable::read($path, $file), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            return [null, "teme-plugin.json is not valid JSON: {$exception->getMessage()}."];
        }

        if (! is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return [null, is_file($file) ? 'teme-plugin.json must hold one JSON object.' : 'The plugin has no teme-plugin.json.'];
        }

        /** @var array<string, mixed> $raw */
        return [$raw, null];
    }

    /**
     * What Composer installed for a package, from installed.json, as
     * Composer's autoloader and Laravel read it; the folder's own
     * composer.json only for a plugin_paths folder, which Composer didn't
     * install, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    private function composerJson(string $path, ?string $package): ?array
    {
        if ($package !== null) {
            return app(InstalledPackages::class)->entry($package) ?? ['name' => $package];
        }

        $file = $path.'/composer.json';
        $composer = is_file($file) ? json_decode(PluginFileUnreadable::read($path, $file), true) : null;

        return is_array($composer) ? $composer : null;
    }

    /**
     * The plugin_paths folders that hold a manifest, each once.
     *
     * @return list<string>
     */
    private function configuredPaths(): array
    {
        $paths = config('teme.plugin_paths');
        $found = [];

        foreach (is_array($paths) ? $paths : [] as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            // Relative entries are against the project root, not the process cwd
            // (public/ under a web server): issue #339.
            $resolved = self::absolutePath($path);

            if (is_file($resolved.'/teme-plugin.json')) {
                $found[] = realpath($resolved) ?: $resolved;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * An absolute folder path: absolute entries unchanged, relative ones from base_path().
     */
    private static function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return base_path($path);
    }
}
