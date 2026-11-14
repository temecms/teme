<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\Manifest;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Updates\TemeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Whether this server can restore a backup, by its manifest (I-2405): Teme,
 * the migrations, PostgreSQL, the theme and the packages, each named when
 * it can't. Reads this server's facts only.
 */
final class RestoreCompatibility
{
    /** Packages named in one problem; the rest are counted. */
    private const int NAMED = 10;

    /**
     * Every problem that refuses the restore, and every note that doesn't.
     *
     * @return array{problems: list<string>, notes: list<string>}
     */
    public static function check(Manifest $manifest): array
    {
        $backup = $manifest->toArray();
        $database = (array) ($backup['database'] ?? []);
        [$problems, $notes] = [[], []];

        $schema = is_array($backup['schema'] ?? null) ? $backup['schema'] : null;
        // A backup taken by a newer image than the schema it holds (a deploy's, before migrating) is the schema's release (I-?? draft).
        $image = (string) ($backup['teme'] ?? '');
        $theirs = (string) ($schema['teme'] ?? $backup['teme'] ?? '');
        $theirsTagged = ($schema === null ? ($backup['tagged'] ?? null) : ($schema['tagged'] ?? null)) === true;
        $byNewerImage = $schema !== null && (string) ($schema['teme'] ?? '') !== $image;
        $ours = (string) config('teme.version');
        $oursTagged = TemeVersion::tagged();
        // Only two releases compare: every image before I-?? (draft) wrote 0.1.0, untagged.
        $comparable = $theirsTagged && $oursTagged;
        // The same string is the same Teme only when both are releases or both aren't (issue #284).
        $teme = $theirs === $ours && $theirsTagged === $oursTagged ? 0 : ($comparable ? self::compare($theirs, $ours) : null);

        if ($teme === 1) {
            $problems[] = "This backup was made by Teme {$theirs}, newer than this server’s Teme {$ours}. Deploy Teme {$theirs} or later here first.";
        }

        $newest = $database['newest_migration'] ?? null;

        if (is_string($newest) && ! in_array($newest, self::migrationNames(), true)) {
            $problems[] = "This backup’s database has migrations this Teme doesn’t know, up to {$newest}. Deploy the Teme that made it, or a later one, first.";
        }

        $server = (string) DB::selectOne('SHOW server_version')->server_version;

        if ((int) ($database['postgres_major'] ?? 0) > (int) $server) {
            $postgres = (string) ($database['postgres'] ?? '');
            $problems[] = "This backup comes from PostgreSQL {$postgres}, newer than this server’s PostgreSQL ".strtok($server, ' ').". Restore it onto PostgreSQL {$postgres} or later.";
        }

        $theme = $backup['theme'] ?? null;

        if (is_array($theme)) {
            $problem = self::themeProblem((string) ($theme['code'] ?? ''), (string) ($theme['version'] ?? ''), $byNewerImage);

            if ($problem !== null) {
                $problems[] = $problem;
            }

            $note = $byNewerImage ? self::themeNote($image, $theirs, (string) ($theme['code'] ?? ''), (string) ($theme['version'] ?? '')) : null;

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        $differ = self::packagesThatDiffer((array) ($backup['packages'] ?? []));

        if ($differ !== '') {
            // A newer Teme updates and drops packages as it should; the same Teme runs the same ones (I-2405).
            if ($byNewerImage) {
                $notes[] = "This backup was taken by Teme {$image} while its database was Teme {$theirs}’s. These packages differ from the backup’s site: {$differ}.";
            } elseif ($teme === -1) {
                $notes[] = "This server’s Teme {$ours} is newer than the backup’s {$theirs}. These packages differ from the backup’s site: {$differ}.";
            } elseif ($teme === null) {
                $notes[] = "This backup was made by Teme {$theirs} and this server runs {$ours}, which Teme can’t compare. These packages differ from the backup’s site: {$differ}.";
            } else {
                $problems[] = "This server runs the backup’s Teme {$theirs} without the packages its site ran with: {$differ}. Deploy the same packages first.";
            }
        }

        return ['problems' => $problems, 'notes' => $notes];
    }

    /**
     * The newest of this code's migrations, by name.
     */
    public static function newestMigration(): ?string
    {
        $names = self::migrationNames();

        return $names === [] ? null : max($names);
    }

    /**
     * How many of this code's migrations this database hasn't run.
     */
    public static function pendingMigrations(): int
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return count(self::migrationNames());
        }

        return count(array_diff(self::migrationNames(), $migrator->getRepository()->getRan()));
    }

    /**
     * @return list<string>
     */
    private static function migrationNames(): array
    {
        $migrator = app('migrator');

        return array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
    }

    /**
     * The backup's theme as the newer image's: a note when it is newer than
     * the one installed here and usable, which the database runs with.
     */
    private static function themeNote(string $image, string $theirs, string $code, string $version): ?string
    {
        $installed = app(ThemeRegistry::class)->find($code);

        if ($installed === null || ! $installed->usable() || $installed->manifest === null) {
            return null;
        }

        $here = $installed->manifest->version();

        return self::compare($version, $here) === 1
            ? "This backup was taken by Teme {$image}, whose theme {$code} is {$version}; this server has {$here}. The database is Teme {$theirs}’s, which runs with it."
            : null;
    }

    private static function themeProblem(string $code, string $version, bool $byNewerImage = false): ?string
    {
        $installed = app(ThemeRegistry::class)->find($code);

        if ($installed === null) {
            return "The backup’s theme, {$code} {$version}, isn’t installed here. Deploy it first.";
        }

        if (! $installed->usable() || $installed->manifest === null) {
            return "The backup’s theme, {$code}, is installed here but can’t be used: ".($installed->errors[0] ?? 'its manifest can’t be read.');
        }

        $here = $installed->manifest->version();

        return ! $byNewerImage && self::compare($version, $here) === 1
            ? "The backup’s theme, {$code}, is {$here} here, older than the backup’s {$version}. Deploy {$version} or later first."
            : null;
    }

    /**
     * The backup's packages missing here or older here, as words; '' when none.
     *
     * @param  array<array-key, mixed>  $theirs
     */
    private static function packagesThatDiffer(array $theirs): string
    {
        $ours = Manifest::packages();
        $differ = [];

        foreach ($theirs as $name => $version) {
            $here = $ours[(string) $name] ?? null;

            if ($here === null || self::compare((string) $version, $here) === 1) {
                $differ[] = "{$name} {$version} (here: ".($here ?? 'missing').')';
            }
        }

        $more = count($differ) - self::NAMED;

        return implode(', ', array_slice($differ, 0, self::NAMED)).($more > 0 ? " and {$more} more" : '');
    }

    /**
     * 1 when $theirs is newer than $ours, 0 the same, -1 older; for versions
     * that aren't numbers (dev-main), 0 when equal, else null.
     */
    private static function compare(string $theirs, string $ours): ?int
    {
        [$theirs, $ours] = [ltrim($theirs, 'vV'), ltrim($ours, 'vV')];

        if (preg_match('/^\d+(\.\d+)*/', $theirs) !== 1 || preg_match('/^\d+(\.\d+)*/', $ours) !== 1) {
            return $theirs === $ours ? 0 : null;
        }

        return version_compare($theirs, $ours);
    }
}
