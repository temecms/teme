<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\ThemeRegistry;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use InvalidArgumentException;
use Teme\Contracts\Settings\Setting;

/**
 * What a backup is (I-2405), read before a restore on another server
 * and by the checks: Teme's version; the database's PostgreSQL, newest
 * migration and rows; the media and every original's SHA-256; the active
 * theme and every package's version. JSON with a format version, kept in
 * the repository beside the dump; never a secret, never code.
 */
final readonly class Manifest
{
    /** The manifest's format; a reader refuses any other. */
    public const int FORMAT = 1;

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data) {}

    /**
     * The manifest of a run, from the moment it read and the media it took.
     */
    public static function make(string $runId, CarbonImmutable $madeAt, DatabaseMoment $moment, MediaInventory $media): self
    {
        $version = $moment->serverVersion();
        $migrations = $moment->migrations();

        return new self([
            'format' => self::FORMAT,
            'run' => $runId,
            'made_at' => $madeAt->utc()->format('Y-m-d\TH:i:s.uP'),
            'teme' => (string) config('teme.version'),
            'tagged' => (bool) config('teme.tagged'),
            'schema' => $moment->deployedRelease(),
            'database' => [
                'file' => BackupSnapshots::DUMP_FILE,
                'postgres' => $version,
                'postgres_major' => (int) $version,
                'newest_migration' => $migrations['newest'],
                'migrations' => $migrations['count'],
                'tables' => $moment->tableCounts(),
                'structure_only' => BackupTables::STRUCTURE_ONLY,
            ],
            'media' => $media->manifest(),
            'theme' => self::theme($moment),
            'packages' => self::packages(),
            'app_key' => BackupSnapshots::APP_KEY_FILE,
        ]);
    }

    /**
     * The theme a restore must find installed, as the dumped settings name
     * it: the design group's override read in the moment, or the setting's
     * default; null when that theme can't draw the site and core's fallback
     * views do (I-2405).
     *
     * @return array{code: string, version: string}|null
     */
    private static function theme(DatabaseMoment $moment): ?array
    {
        $chosen = $moment->override('design', 'theme');

        if (! is_string($chosen)) {
            $declared = array_values(array_filter(
                app(SettingsRegistry::class)->find('design')->settings,
                fn (Setting $setting): bool => $setting->key === 'theme',
            ))[0] ?? null;
            $chosen = is_string($declared?->default) ? $declared->default : '';
        }

        $theme = $chosen === '' ? null : app(ThemeRegistry::class)->find($chosen);

        return $theme === null || ! $theme->usable() || $theme->manifest === null
            ? null
            : ['code' => $theme->code, 'version' => $theme->manifest->version()];
    }

    /**
     * Reads a manifest a backup holds. One of another format is refused:
     * code that doesn't know it can't read it safely.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('That isn’t a manifest of a format this Teme reads.');
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    /**
     * Every package Teme runs with, but development ones, by name, at the
     * version Composer installed.
     *
     * @return array<string, string>
     */
    public static function packages(): array
    {
        $packages = [];

        foreach (array_unique(InstalledVersions::getInstalledPackages()) as $name) {
            $version = InstalledVersions::getPrettyVersion($name);

            if ($version !== null && InstalledVersions::isInstalled($name, false)) {
                $packages[$name] = $version;
            }
        }

        ksort($packages);

        return $packages;
    }

    /**
     * The rows of every table the dump holds rows of, by name.
     *
     * @return array<string, int>
     */
    public function tables(): array
    {
        /** @var array{tables: array<string, int>} $database */
        $database = $this->data['database'];

        return $database['tables'];
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
