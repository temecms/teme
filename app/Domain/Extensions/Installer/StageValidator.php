<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Plugins\ManifestValidator as PluginManifestValidator;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use App\Domain\Themes\ThemeRegistry;
use Closure;
use JsonException;

/**
 * Checks a staged release before it may run: that it is exactly what was
 * locked, and that the package asked for is a valid plugin or theme that
 * shadows nothing of Teme's or of another extension's. Reads files and
 * Composer's data only, never a package's code (docs/plans/plugin-installer.md).
 */
final readonly class StageValidator
{
    /** A class Composer writes into every project's classmap. */
    private const array COMPOSER_OWN = ['Composer\\InstalledVersions'];

    /** @var Closure(): array{plugins: list<string>, themes: list<string>} */
    private Closure $imageCodes;

    /**
     * @param  (Closure(): array{plugins: list<string>, themes: list<string>})|null  $imageCodes  the codes the image's own plugins and themes use
     */
    public function __construct(
        private ComposerRunner $composer,
        private PluginManifestValidator $plugins,
        private ThemeManifestValidator $themes,
        private string $imageVendor,
        ?Closure $imageCodes = null,
    ) {
        $this->imageCodes = $imageCodes ?? static fn (): array => [
            'plugins' => array_values(array_map(fn ($plugin): string => $plugin->code, app(PluginRegistry::class)->all())),
            'themes' => array_values(array_map(fn ($theme): string => $theme->code, app(ThemeRegistry::class)->all())),
        ];
    }

    /**
     * Whether the release holds exactly what its lock says: the lock matches
     * composer.json, every package is pinned to a reference, and what was
     * installed is what was locked. Returns the reasons it isn't.
     *
     * @return list<string>
     */
    public function verify(string $release): array
    {
        $validated = $this->composer->validate($release);

        if (! $validated->ok()) {
            return ['The lock doesn’t match composer.json: '.$validated->lastLines(4)];
        }

        $locked = [];
        $problems = [];

        foreach (self::locked($release) as $package) {
            $reference = $package['dist']['reference'] ?? $package['source']['reference'] ?? null;

            if (! is_string($reference) || $reference === '') {
                $problems[] = "{$package['name']} isn’t locked to an exact reference.";
            }

            $locked[$package['name']] = [(string) ($package['version'] ?? ''), $reference];
        }

        $installed = [];

        foreach (self::installedPackages($release) as $package) {
            $installed[$package['name']] = [(string) ($package['version'] ?? ''), $package['dist']['reference'] ?? $package['source']['reference'] ?? null];
        }

        ksort($locked);
        ksort($installed);

        if ($locked !== $installed) {
            $problems[] = 'What Composer installed isn’t exactly what it locked.';
        }

        return $problems;
    }

    /**
     * What the staged release holds that refuses it, or waits for agreement.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     * @param  string|null  $previous  the release that runs now, or null
     */
    public function validate(ExtensionRequest $request, string $release, array $pins, ?string $previous): StageOutcome
    {
        $locked = [];

        foreach (self::locked($release) as $package) {
            $locked[$package['name']] = $package;
        }

        $refusals = [];
        $needs = [];
        $warnings = [];
        $package = $request->package;
        $changes = $package !== null && ($request->action === ExtensionAction::Install || $request->action === ExtensionAction::Update);

        if ($package !== null && isset($pins['replace'][$package])) {
            $refusals[] = "{$package} is part of Teme’s build; change it where the site is built.";
        }

        if ($changes && ! isset($locked[$package])) {
            $refusals[] = "{$package} isn’t among the packages Composer installed.";
        }

        if ($changes && isset($locked[$package])) {
            array_push($refusals, ...$this->package($request->kind, $locked[$package], $release, $locked));
        }

        foreach ($locked as $name => $entry) {
            if (isset($pins['replace'][$name]) && $name !== $package) {
                $refusals[] = "{$name} is part of Teme’s build, so the release can’t hold a second copy.";
            }
        }

        array_push($refusals, ...$this->collisions($release));

        $platform = $this->composer->checkPlatform($release);

        if (! $platform->ok()) {
            $refusals[] = 'This server’s PHP doesn’t have what the packages need: '.$platform->lastLines(6);
        }

        $advisories = $this->composer->audit($release);

        if ($advisories === null) {
            $warnings[] = 'Teme couldn’t reach the security advisory database, so the packages weren’t checked against it.';
        } else {
            foreach ($advisories as $name => $found) {
                $titles = implode('; ', array_map(fn (array $advisory): string => (string) ($advisory['title'] ?? $advisory['advisoryId'] ?? 'an advisory'), $found));
                $refusals[] = "{$name} has a known security advisory: {$titles}.";
            }
        }

        foreach ($locked as $name => $entry) {
            $files = $entry['autoload']['files'] ?? [];

            if ($name !== $package && is_array($files) && $files !== []) {
                $needs['files'][$name] = array_values(array_map('strval', $files));
            }

            if (($entry['type'] ?? null) === 'composer-plugin') {
                $needs['composer_plugins'][] = $name;
            }
        }

        if ($changes && $previous !== null) {
            $needs = [...$needs, ...$this->changesOfOrigin($request, $release, $previous)];
        }

        return new StageOutcome(array_values(array_unique($refusals)), $needs, $warnings);
    }

    /**
     * What refuses the package asked for: its type, its manifest, its code,
     * its own autoload.files.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<string, mixed>>  $locked
     *
     * @return list<string>
     */
    private function package(ExtensionKind $kind, array $entry, string $release, array $locked): array
    {
        $name = (string) $entry['name'];

        if (($entry['type'] ?? null) !== $kind->packageType()) {
            return ["{$name} isn’t a {$kind->value}: its type is ".(is_string($entry['type'] ?? null) ? $entry['type'] : 'library').", not {$kind->packageType()}."];
        }

        $path = "{$release}/vendor/{$name}";
        $manifest = self::json($path.'/'.$kind->packageType().'.json');

        if ($manifest === null) {
            return ["{$name} has no readable {$kind->packageType()}.json."];
        }

        $refusals = [];

        if ($kind === ExtensionKind::Plugin && is_array($entry['autoload']['files'] ?? null) && $entry['autoload']['files'] !== []) {
            $refusals[] = "{$name} declares autoload.files, which would run on every request; a plugin registers its code through its class instead.";
        }

        $errors = $kind === ExtensionKind::Plugin
            ? $this->plugins->errors($manifest, $path, $entry)
            : $this->themes->errors($manifest, $path);

        foreach ($errors as $error) {
            $refusals[] = "{$name}: {$error}";
        }

        $code = $manifest['code'] ?? null;

        if (is_string($code) && in_array($code, $this->takenCodes($kind, $release, $locked, $name), true)) {
            $refusals[] = "The {$kind->value} code \"{$code}\" is already used by another {$kind->value}; a package must never shadow another.";
        }

        return $refusals;
    }

    /**
     * The codes every other plugin or theme uses, the image's and the release's.
     *
     * @param  array<string, array<string, mixed>>  $locked
     *
     * @return list<string>
     */
    private function takenCodes(ExtensionKind $kind, string $release, array $locked, string $except): array
    {
        $image = ($this->imageCodes)();
        $codes = $kind === ExtensionKind::Plugin ? $image['plugins'] : $image['themes'];

        foreach ($locked as $name => $entry) {
            if ($name !== $except && ($entry['type'] ?? null) === $kind->packageType()) {
                $code = self::json("{$release}/vendor/{$name}/{$kind->packageType()}.json")['code'] ?? null;

                if (is_string($code)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Every autoload prefix or class of the release that equals or falls
     * under one of the image's: core's code always wins, so a clash could
     * only mean a broken or hostile package.
     *
     * @return list<string>
     */
    private function collisions(string $release): array
    {
        $image = [];

        foreach (['autoload_psr4.php', 'autoload_namespaces.php'] as $file) {
            foreach (array_keys(self::map($this->imageVendor.'/composer/'.$file)) as $prefix) {
                if ($prefix !== '') {
                    $image[] = rtrim($prefix, '\\').'\\';
                }
            }
        }

        $names = [];

        foreach (['autoload_psr4.php', 'autoload_namespaces.php'] as $file) {
            foreach (array_keys(self::map("{$release}/vendor/composer/{$file}")) as $prefix) {
                $names[] = rtrim($prefix, '\\').'\\';
            }
        }

        foreach (array_keys(self::map("{$release}/vendor/composer/autoload_classmap.php")) as $class) {
            if (! in_array($class, self::COMPOSER_OWN, true)) {
                $names[] = $class;
            }
        }

        $refusals = [];

        foreach (array_unique($names) as $name) {
            foreach ($image as $prefix) {
                if ($name === $prefix || str_starts_with($name, $prefix)) {
                    $refusals[] = "The packages declare {$name}, which is Teme’s own ({$prefix}); core’s code always wins, so it is refused.";

                    continue 2;
                }
            }
        }

        return $refusals;
    }

    /**
     * What waits because the package changes where it came from, or an
     * upload replaces the version installed now.
     *
     * @return array<string, mixed>
     */
    private function changesOfOrigin(ExtensionRequest $request, string $release, string $previous): array
    {
        $needs = [];
        $package = (string) $request->package;
        $before = self::json($previous.'/composer.json')['extra']['teme']['sources'][$package] ?? null;

        if (is_string($before) && $request->sourceKind !== null && $before !== $request->sourceKind) {
            $needs['source'] = ['package' => $package, 'from' => $before, 'to' => $request->sourceKind];
        }

        $installed = null;

        foreach (self::locked($previous) as $entry) {
            if ($entry['name'] === $package) {
                $installed = (string) ($entry['version'] ?? '');
            }
        }

        if ($installed !== null && $request->sourceKind === 'upload') {
            foreach (self::locked($release) as $entry) {
                if ($entry['name'] === $package) {
                    $needs['replace'] = ['package' => $package, 'from' => $installed, 'to' => (string) ($entry['version'] ?? '')];
                }
            }
        }

        return $needs;
    }

    /**
     * The release's locked packages.
     *
     * @return list<array<string, mixed>>
     */
    public static function locked(string $release): array
    {
        $lock = self::json($release.'/composer.lock');

        return self::named(is_array($lock['packages'] ?? null) ? $lock['packages'] : []);
    }

    /**
     * What Composer installed into the release.
     *
     * @return list<array<string, mixed>>
     */
    private static function installedPackages(string $release): array
    {
        $installed = self::json($release.'/vendor/composer/installed.json');

        return self::named(is_array($installed['packages'] ?? null) ? $installed['packages'] : (is_array($installed) ? $installed : []));
    }

    /**
     * @param  array<mixed>  $packages
     *
     * @return list<array<string, mixed>>
     */
    private static function named(array $packages): array
    {
        $named = [];

        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $named[] = $package;
            }
        }

        return $named;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(string $file): ?array
    {
        try {
            $json = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * One of Composer's generated autoload maps, read as data.
     *
     * @return array<string, mixed>
     */
    private static function map(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $map = (static function (string $file): mixed {
            return require $file;
        })($file);

        return is_array($map) ? $map : [];
    }
}
