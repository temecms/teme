<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\InstalledPackages;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginRegistry;
use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use ReflectionProperty;

/**
 * The fixture plugins under tests/Fixtures/plugins, and the shared helper
 * that points Teme at them: their folders as `teme.plugin_paths`, copies a
 * test may change, Composer's runtime data listing them as installed, and
 * the Teme version their requires.teme (^0.5) accepts, which an untagged
 * build (0.1.0) doesn't.
 */
final class PluginFixtures
{
    /** The release the plugin API arrives in. */
    public const string TEME = '0.5.0';

    private const array COMPOSER_STATE = ['installed', 'installedByVendor', 'installedIsLocalDir'];

    /** @var list<mixed>|null Composer's runtime data before a test changed it */
    private static ?array $composerState = null;

    /** @var list<string> the copies made, deleted by forget() */
    private static array $copies = [];

    /**
     * Points Teme at these fixture folders (or copies' paths), with no
     * plugin cache and the Teme version the fixtures need, and forgets every
     * discovery.
     */
    public static function use(string ...$folders): void
    {
        config([
            'teme.plugin_paths' => array_map(fn (string $folder): string => self::absolute($folder) ? $folder : self::path($folder), $folders),
            'teme.plugin_cache' => '',
            'teme.version' => self::TEME,
        ]);

        app(PluginRegistry::class)->flush();
    }

    /**
     * The consent EnablePlugin keeps for this manifest, agreeing to all it
     * asks: its capabilities and hooks, no host, and the kinds of notice and
     * the plugins it requires.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return array<string, mixed>
     */
    public static function agreed(array $manifest): array
    {
        $read = new Manifest($manifest);
        $notices = Consent::declaredNotices($read);
        $requires = Consent::declaredRequires($read);

        return [
            'capabilities' => $read->capabilities(),
            'hosts' => [],
            'hooks' => $read->hooks(),
            ...($notices === [] ? [] : ['notices' => $notices]),
            ...($requires === [] ? [] : ['requires' => $requires]),
        ];
    }

    /**
     * Loads the fixture's classes from its own folder from now on, ahead of
     * any copy's: PHP loads a class once a process, and a copy is deleted
     * after its test, so a class read from one would name a missing file.
     */
    public static function loadFromOriginal(string $folder): void
    {
        static $registered = [];

        if (isset($registered[$folder])) {
            return;
        }

        $loader = new ClassLoader;

        foreach (self::composer($folder)['autoload']['psr-4'] ?? [] as $prefix => $folders) {
            $loader->addPsr4((string) $prefix, array_map(fn (string $path): string => self::path($folder).'/'.$path, (array) $folders));
        }

        $loader->register(true);
        $registered[$folder] = true;
    }

    /**
     * Whether $path is absolute on Unix (/…), a Windows drive (C:\… or C:/…),
     * or a UNC share (\\server\…).
     */
    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * The fixture plugin's folder.
     */
    public static function path(string $folder): string
    {
        return base_path('tests/Fixtures/plugins/'.$folder);
    }

    /**
     * The fixture plugin's teme-plugin.json, decoded.
     *
     * @return array<string, mixed>
     */
    public static function manifest(string $folder): array
    {
        return json_decode((string) file_get_contents(self::path($folder).'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The fixture plugin's composer.json, decoded.
     *
     * @return array<string, mixed>
     */
    public static function composer(string $folder): array
    {
        return json_decode((string) file_get_contents(self::path($folder).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A copy of a fixture plugin a test may change: its manifest's keys
     * replaced by $manifest, its composer.json's by $composer, and $files
     * (relative path => contents) added. Returns the copy's folder.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $composer
     * @param  array<string, string>  $files
     */
    public static function copy(string $folder, array $manifest = [], array $composer = [], array $files = []): string
    {
        $disk = new Filesystem;
        $copy = storage_path('framework/testing/plugins/'.$folder.'-'.bin2hex(random_bytes(6)));

        $disk->copyDirectory(self::path($folder), $copy);
        $disk->put($copy.'/teme-plugin.json', json_encode([...self::manifest($folder), ...$manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $disk->put($copy.'/composer.json', json_encode([...self::composer($folder), ...$composer], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        foreach ($files as $path => $contents) {
            $disk->ensureDirectoryExists(dirname($copy.'/'.$path));
            $disk->put($copy.'/'.$path, $contents);
        }

        self::$copies[] = $copy;

        return $copy;
    }

    /**
     * Lists these packages, name => folder, in Composer's runtime data as
     * installed plugins, as an image's `composer require` would, and writes
     * the installed.json Composer would: each package's own composer.json,
     * with $installed[name]'s keys over it where Composer installed
     * something else (a private or `package` repository).
     *
     * $references[name] is the commit Composer reports for it, null when absent.
     *
     * @param  array<string, string>  $packages
     * @param  array<string, array<string, mixed>>  $installed
     * @param  array<string, string>  $references
     */
    public static function installed(array $packages, array $installed = [], array $references = []): void
    {
        self::$composerState ??= array_map(fn (string $name): mixed => (new ReflectionProperty(InstalledVersions::class, $name))->getValue(), self::COMPOSER_STATE);

        $versions = [];
        $json = [];

        foreach ($packages as $name => $folder) {
            $path = self::absolute($folder) ? $folder : self::path($folder);
            $versions[$name] = ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => $references[$name] ?? null, 'type' => 'teme-plugin', 'install_path' => $path, 'aliases' => [], 'dev_requirement' => false];
            $own = is_file($path.'/composer.json') ? json_decode((string) file_get_contents($path.'/composer.json'), true) : [];
            $json[] = [...(is_array($own) ? $own : []), 'name' => $name, 'version' => '1.0.0', 'type' => 'teme-plugin', 'install-path' => $path, ...($installed[$name] ?? [])];
        }

        InstalledVersions::reload([
            'root' => ['name' => 'fixture/root', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => base_path(), 'aliases' => [], 'dev' => true],
            'versions' => $versions,
        ]);

        $vendor = storage_path('framework/testing/plugins/vendor-'.bin2hex(random_bytes(6)));
        (new Filesystem)->ensureDirectoryExists($vendor.'/composer');
        file_put_contents($vendor.'/composer/installed.json', json_encode(['packages' => $json], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::$copies[] = $vendor;
        app()->instance(InstalledPackages::class, new InstalledPackages($vendor.'/composer/installed.json'));

        app(PluginRegistry::class)->flush();
    }

    /**
     * Runs $then as a site answering a request for $path, not as the test
     * suite: SafeMode::active() then reads the request.
     */
    public static function serving(string $path, Closure $then): mixed
    {
        $app = app();
        $console = new ReflectionProperty($app, 'isRunningInConsole');
        $request = $app->make('request');
        $app['env'] = 'production';
        $console->setValue($app, false);
        $app->instance('request', Request::create('/'.ltrim($path, '/')));

        try {
            return $then();
        } finally {
            $app['env'] = 'testing';
            $console->setValue($app, null);
            $app->instance('request', $request);
        }
    }

    /**
     * Makes this file fail when it is read, where the machine lets a test do
     * that: a link to /proc/self/mem on Linux, else no permission at all.
     * Returns false when neither works, as for root on a machine without /proc.
     */
    public static function unreadable(string $file): bool
    {
        @unlink($file);

        if (is_readable('/proc/self/mem') && @symlink('/proc/self/mem', $file)) {
            return true;
        }

        file_put_contents($file, '{}');
        chmod($file, 0);
        clearstatcache();

        return @file_get_contents($file) === false;
    }

    /**
     * A cache file that leaves a marker when Teme reads it, and the marker's path.
     *
     * @return array{0: string, 1: string}
     */
    public static function markingCache(): array
    {
        $cache = storage_path('framework/testing/plugins/cache-'.bin2hex(random_bytes(6)).'.php');
        $marker = $cache.'.read';
        (new Filesystem)->ensureDirectoryExists(dirname($cache));
        file_put_contents($cache, '<?php file_put_contents('.var_export($marker, true).", 'read'); return [];\n");
        self::$copies[] = $cache;
        self::$copies[] = $marker;

        return [$cache, $marker];
    }

    /**
     * Puts Composer's runtime data back, and deletes every copy made.
     */
    public static function forget(): void
    {
        if (self::$composerState !== null) {
            foreach (self::COMPOSER_STATE as $i => $name) {
                (new ReflectionProperty(InstalledVersions::class, $name))->setValue(null, self::$composerState[$i]);
            }

            self::$composerState = null;
        }

        foreach (self::$copies as $copy) {
            is_dir($copy) ? (new Filesystem)->deleteDirectory($copy) : @unlink($copy);
        }

        self::$copies = [];
    }
}
