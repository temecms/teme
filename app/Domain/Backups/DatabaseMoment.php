<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One moment of the database, held while a backup reads it (I-358): a
 * REPEATABLE READ, READ ONLY transaction on a session of its own exports
 * its snapshot and reads what the manifest says of the database and the
 * media rows, and stays open until pg_dump has copied the database as that
 * same snapshot sees it. The manifest, the dump and the media files then
 * describe one instant.
 */
final class DatabaseMoment
{
    /** The session the moment is held on, made when it opens. */
    public const string CONNECTION = 'teme_backup_moment';

    /** Media rows read at a time. */
    public const int CHUNK = 1000;

    private bool $open = true;

    private function __construct(private readonly ConnectionInterface $session, public readonly string $snapshot) {}

    /**
     * Opens the moment on a new session to the database the default
     * connection names now. Its own session, because the caller's may be
     * inside a transaction already, and a transaction's isolation is set
     * only at its start; and because pg_dump, importing the snapshot, never
     * sees the exporting transaction's own uncommitted rows.
     */
    public static function open(): self
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        $session = DB::connectUsing(self::CONNECTION, [...$config, 'name' => self::CONNECTION], force: true);

        try {
            $session->beginTransaction();
            // Before any query: the first statement after it takes the snapshot.
            $session->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            // The moment waits, idle, while restic reads the dump; a server's own limit mustn't end it.
            $session->unprepared('SET LOCAL idle_in_transaction_session_timeout = 0');
            $snapshot = (string) $session->selectOne('SELECT pg_export_snapshot() AS snapshot')->snapshot;
        } catch (Throwable $failed) {
            DB::purge(self::CONNECTION);

            throw $failed;
        }

        return new self($session, $snapshot);
    }

    /**
     * PostgreSQL's version, as the server gives it up to its first space:
     * 18.6.
     */
    public function serverVersion(): string
    {
        return Str::before((string) $this->session->scalar('SHOW server_version'), ' ');
    }

    /**
     * The newest migration that has run, by name, as code's are ordered,
     * and how many have.
     *
     * @return array{newest: string|null, count: int}
     */
    public function migrations(): array
    {
        $row = $this->session->selectOne('SELECT max(migration) AS newest, count(*) AS count FROM migrations');

        return ['newest' => $row->newest === null ? null : (string) $row->newest, 'count' => (int) $row->count];
    }

    /**
     * The release the newest successful deploy ran, as {teme, tagged}: the
     * version the database was last migrated by. Null when there is no
     * deploy recorded, or the table or its tagged column isn't there yet.
     *
     * @return array{teme: string, tagged: bool}|null
     */
    public function deployedRelease(): ?array
    {
        $has = $this->session->selectOne("SELECT to_regclass('deploy_runs') IS NOT NULL AS has_table, EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'deploy_runs' AND column_name = 'tagged') AS has_column");

        if (! $has->has_table || ! $has->has_column) {
            return null;
        }

        $row = $this->session->selectOne('SELECT version, tagged FROM deploy_runs WHERE exit_code = 0 ORDER BY finished_at DESC LIMIT 1');

        return $row === null ? null : ['teme' => (string) $row->version, 'tagged' => (bool) $row->tagged];
    }

    /**
     * Every table's rows as the moment sees them, by name, but the tables a
     * backup keeps as structure only, whose rows it leaves out (I-357).
     *
     * @return array<string, int>
     */
    public function tableCounts(): array
    {
        $tables = array_values(array_diff(
            array_map(
                fn (object $row): string => (string) $row->name,
                $this->session->select('SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename'),
            ),
            BackupTables::STRUCTURE_ONLY,
        ));

        if ($tables === []) {
            return [];
        }

        // One statement. Names come from the catalog, quoted as identifiers, and bound as values.
        $sql = implode(' UNION ALL ', array_map(
            fn (string $table): string => 'SELECT ?::text AS name, count(*) AS n FROM "'.str_replace('"', '""', $table).'"',
            $tables,
        ));

        $counts = [];

        foreach ($this->session->select($sql, $tables) as $row) {
            $counts[(string) $row->name] = (int) $row->n;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Each media row the moment sees, trashed ones too, by ID, CHUNK at a
     * time: its id, kind, original_path, size_bytes, sha256 and variants
     * (JSON text).
     *
     * @param  Closure(object): void  $each
     */
    public function eachMedia(Closure $each): void
    {
        $last = '';

        do {
            $rows = $this->session->select(
                'SELECT id, kind, original_path, size_bytes, sha256, variants FROM media WHERE id > ? ORDER BY id LIMIT ?',
                [$last, self::CHUNK],
            );

            foreach ($rows as $row) {
                $each($row);
                $last = (string) $row->id;
            }
        } while (count($rows) === self::CHUNK);
    }

    /**
     * A settings group's stored override for one key, as the moment sees
     * it, decoded; null when the group stores none.
     */
    public function override(string $group, string $key): mixed
    {
        $row = $this->session->selectOne('SELECT overrides -> ?::text AS value FROM settings WHERE group_key = ?', [$key, $group]);

        return $row === null || $row->value === null ? null : json_decode((string) $row->value, true);
    }

    /**
     * Ends the moment: it wrote nothing, so it rolls back, and its session
     * closes, whatever state a failed query left it in.
     */
    public function close(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        try {
            $this->session->rollBack();
        } catch (Throwable) {
            // Closing the session, below, ends the transaction anyway.
        }

        DB::purge(self::CONNECTION);
    }
}
