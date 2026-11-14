<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\RuntimeException as ProcessFailed;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds restic and PostgreSQL's pg_dump and pg_restore where the
 * configuration says, and checks each is a version Teme can back up with
 * (I-366): restic at or above the version Teme is tested with, the
 * PostgreSQL tools at or above both PostgreSQL 18 and the database server,
 * and the two from one installation.
 */
final readonly class BackupTools
{
    /** The restic Teme is tested with (I-2400); an older one is refused. */
    public const string RESTIC_VERSION = '0.19.1';

    /** The oldest PostgreSQL whose tools Teme backs up with. */
    public const int POSTGRES_MAJOR = 18;

    /** How long a program may take to say its version, in seconds. */
    private const int VERSION_TIMEOUT = 30;

    /**
     * restic's path: as configured, or found on the PATH.
     *
     * @throws BackupToolMissing
     */
    public function restic(): string
    {
        $configured = trim((string) config('teme.backups.restic'));

        return self::locate($configured) ?? throw BackupToolMissing::restic($configured);
    }

    /**
     * pg_dump's or pg_restore's path: in the configured folder, or found on
     * the PATH when none is configured.
     *
     * @throws BackupToolMissing
     */
    public function postgres(PostgresProgram $program): string
    {
        $folder = trim((string) config('teme.backups.postgres_bindir'));
        $found = $folder === ''
            ? self::locate($program->value)
            : self::locate(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.$program->fileName());

        return $found ?? throw BackupToolMissing::postgres($program, $folder);
    }

    /**
     * Each program's path and version, the database server's version, and
     * what is wrong. Runs each program once, and asks the database once.
     */
    public function check(): BackupToolsReport
    {
        $folder = trim((string) config('teme.backups.postgres_bindir'));
        $restic = $this->found('restic', trim((string) config('teme.backups.restic')), fn (): string => $this->restic(), ['version', '--json'], self::resticVersionFrom(...));
        $dump = $this->found('pg_dump', $folder, fn (): string => $this->postgres(PostgresProgram::Dump), ['--version'], self::postgresVersionFrom(...));
        $restore = $this->found('pg_restore', $folder, fn (): string => $this->postgres(PostgresProgram::Restore), ['--version'], self::postgresVersionFrom(...));
        $server = Str::before((string) DB::scalar('SHOW server_version'), ' ');

        return new BackupToolsReport([$restic, $dump, $restore], $server, self::problems($restic, $dump, $restore, $server));
    }

    /**
     * What makes these programs unusable for this site, in words people
     * read; empty when restic, pg_dump and pg_restore will all do.
     *
     * @return list<string>
     */
    public static function problems(ToolCheck $restic, ToolCheck $dump, ToolCheck $restore, string $serverVersion): array
    {
        $problems = [];
        $pinned = self::RESTIC_VERSION;
        $major = self::POSTGRES_MAJOR;
        $serverMajor = (int) $serverVersion;

        if ($restic->path === null) {
            $problems[] = BackupToolMissing::restic($restic->looked)->getMessage();
        } elseif ($restic->version === null) {
            $problems[] = "The program at {$restic->path} didn’t answer as restic does. Check that TEME_RESTIC_BINARY names restic.";
        } elseif (version_compare($restic->version, $pinned, '<')) {
            $problems[] = "restic {$restic->version} is older than {$pinned}, the version Teme is tested with. Install restic {$pinned}.";
        }

        foreach ([[$dump, PostgresProgram::Dump], [$restore, PostgresProgram::Restore]] as [$tool, $program]) {
            if ($tool->path === null) {
                $problems[] = BackupToolMissing::postgres($program, $tool->looked)->getMessage();
            } elseif ($tool->version === null) {
                $problems[] = "The program at {$tool->path} didn’t answer as {$program->value} does.";
            } elseif ($tool->major() < $major) {
                $problems[] = "{$program->value} {$tool->version} is older than PostgreSQL {$major}, the oldest Teme backs up with. Install PostgreSQL {$major}’s command-line tools or later.";
            } elseif ($program === PostgresProgram::Dump && $tool->major() < $serverMajor) {
                // pg_dump can't read a newer server; pg_restore must match pg_dump, below.
                $problems[] = "pg_dump {$tool->version} can’t copy this site’s database, which runs PostgreSQL {$serverVersion}. Install PostgreSQL {$serverMajor}’s command-line tools or later.";
            }
        }

        if ($dump->version !== null && $restore->version !== null && $dump->version !== $restore->version) {
            $problems[] = "pg_dump {$dump->version} and pg_restore {$restore->version} come from different PostgreSQL installations. Use the two from one folder.";
        }

        return $problems;
    }

    /**
     * The version `restic version --json` says, such as 0.19.1; null when
     * the answer isn't restic's.
     */
    public static function resticVersionFrom(string $output): ?string
    {
        $answer = json_decode(trim($output), true);
        $version = is_array($answer) && ($answer['message_type'] ?? null) === 'version' ? ($answer['version'] ?? null) : null;

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version) === 1 ? $version : null;
    }

    /**
     * The version `pg_dump --version` or `pg_restore --version` says, such
     * as 18.6; null when the answer isn't PostgreSQL's.
     */
    public static function postgresVersionFrom(string $output): ?string
    {
        return preg_match('/\(PostgreSQL\)\s+(\d+(?:\.\d+)?)/', $output, $match) === 1 ? $match[1] : null;
    }

    /**
     * Whether a configured value is a path, used as given and never looked
     * up: anything with a slash or a backslash in it.
     */
    public static function isPath(string $configured): bool
    {
        return str_contains($configured, '/') || str_contains($configured, '\\');
    }

    /**
     * A path as given, if a program is there; a bare name as a shell would
     * find it on the PATH (with PATHEXT on Windows); null otherwise.
     */
    private static function locate(string $configured): ?string
    {
        if ($configured === '') {
            return null;
        }

        if (self::isPath($configured)) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    /**
     * @param  Closure(): string  $locate
     * @param  list<string>  $arguments
     * @param  Closure(string): ?string  $parse
     */
    private function found(string $name, string $looked, Closure $locate, array $arguments, Closure $parse): ToolCheck
    {
        try {
            $path = $locate();
        } catch (BackupToolMissing) {
            return new ToolCheck($name, $looked, null, null);
        }

        try {
            $answer = Process::env(ChildEnvironment::withoutInherited())->timeout(self::VERSION_TIMEOUT)->run([$path, ...$arguments]);
        } catch (ProcessFailed) {
            return new ToolCheck($name, $looked, $path, null);
        }

        return new ToolCheck($name, $looked, $path, $answer->successful() ? $parse($answer->output()) : null);
    }
}
