<?php

/**
 * Drives PostgreSQL's pg_dump and pg_restore (I-366): the database named by
 * PostgreSQL's own variables in the child's environment, never on its
 * command line, and a password prompt never waited for.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use App\Domain\Backups\BackupTables;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

final readonly class PostgresTools
{
    /** What a database administrator sees in pg_stat_activity while one runs. */
    public const string APPLICATION = 'teme-backup';

    public function __construct(private BackupTools $tools) {}

    /**
     * The variables that point pg_dump or pg_restore at a database: Teme's
     * own connection's by default, or the one given, such as the weekly test
     * restore's spare database. Empty values are left out.
     *
     * @param  array<string, mixed>|null  $connection  a connection's configuration, as config/database.php holds one
     *
     * @return array<string, string>
     */
    public function environment(?array $connection = null): array
    {
        if ($connection === null) {
            /** @var array<string, mixed> $connection */
            $connection = DB::connection()->getConfig();
        }

        $host = $connection['host'] ?? null;

        if (! is_string($host) || trim($host) === '') {
            throw new InvalidArgumentException('Teme backs up one database server: the connection must name one host.');
        }

        return array_filter([
            'PGHOST' => $host,
            'PGPORT' => (string) ($connection['port'] ?? '5432'),
            'PGDATABASE' => (string) ($connection['database'] ?? ''),
            'PGUSER' => (string) ($connection['username'] ?? ''),
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($connection['sslmode'] ?? ''),
            'PGAPPNAME' => self::APPLICATION,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * pg_dump's command for one consistent copy of the database (I-357): in
     * custom format, with its planner statistics, every structure-only
     * table's rows left out, and, given the snapshot a transaction exported
     * with pg_export_snapshot(), the database exactly as that transaction
     * sees it. For restic's --stdin-from-command, with environment() in the
     * same call's environment.
     *
     * @return list<string>
     */
    public function dumpCommand(?string $snapshot = null): array
    {
        $command = [$this->tools->postgres(PostgresProgram::Dump), '--format=custom', '--statistics', '--no-password'];

        foreach (BackupTables::STRUCTURE_ONLY as $table) {
            $command[] = '--exclude-table-data='.$table;
        }

        if ($snapshot !== null) {
            if (preg_match('/^[0-9A-F]+-[0-9A-F]+-[0-9]+$/', $snapshot) !== 1) {
                throw new InvalidArgumentException("[{$snapshot}] isn’t a snapshot PostgreSQL exported.");
            }

            $command[] = '--snapshot='.$snapshot;
        }

        return $command;
    }

    /**
     * Runs pg_dump or pg_restore and waits for it, its database named by
     * environment(), and nothing PostgreSQL reads inherited from Teme's own
     * environment.
     *
     * @param  list<string>  $arguments
     * @param  array<string, mixed>|null  $connection
     */
    public function run(PostgresProgram $program, array $arguments, ?array $connection = null, int $timeout = Restic::TIMEOUT): ProcessResult
    {
        return Process::env([...ChildEnvironment::withoutInherited(), ...$this->environment($connection)])
            ->timeout($timeout)
            ->run([$this->tools->postgres($program), '--no-password', ...$arguments]);
    }
}
