<?php

/**
 * How many of the migrations this code ships the database hasn't run, in
 * one query (I-378). Only the code's own names count, so an image rolled
 * back to fewer migrations still passes, as expand-then-contract needs.
 * It also names them, those flagged as breaking the release before, and the
 * newest the code ships, for teme:deploy.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use App\Domain\Deployment\BreaksPreviousRelease;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

final readonly class PendingMigrations
{
    public function __construct(private Migrator $migrator) {}

    public function count(): int
    {
        $names = array_keys($this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]));

        $ran = DB::table((string) config('database.migrations.table', 'migrations'))->whereIn('migration', $names)->count();

        return count($names) - $ran;
    }

    /**
     * The names of the migrations this code ships that the database hasn't
     * run, in the order they run; every one while the database has no
     * migrations table.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->migrationFiles());

        if (! $this->migrator->repositoryExists()) {
            return $names;
        }

        return array_values(array_diff($names, $this->migrator->getRepository()->getRan()));
    }

    /**
     * Whether no migration has run on this database: a site being installed,
     * with no release running beside it.
     */
    public function databaseIsNew(): bool
    {
        return ! $this->migrator->hasRunAnyMigrations();
    }

    /**
     * Those of $names whose migration says the release before can't run
     * against what it leaves (I-383).
     *
     * @param  list<string>  $names
     *
     * @return list<string>
     */
    public function breakingPreviousRelease(array $names): array
    {
        $files = $this->migrationFiles();

        return array_values(array_filter(
            $names,
            fn (string $name): bool => isset($files[$name]) && $this->migrator->getFilesystem()->getRequire($files[$name]) instanceof BreaksPreviousRelease,
        ));
    }

    /**
     * The newest migration this code ships, by name: the release it is.
     */
    public function newest(): ?string
    {
        $names = array_keys($this->migrationFiles());

        return $names === [] ? null : $names[count($names) - 1];
    }

    /**
     * Whether this code ships the migration $name, as a worker on an older
     * release doesn't.
     */
    public function ships(string $name): bool
    {
        return array_key_exists($name, $this->migrationFiles());
    }

    /**
     * Every migration file this code ships, by name, in order.
     *
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        return $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);
    }
}
