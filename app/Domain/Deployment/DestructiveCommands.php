<?php

/**
 * Keeps the commands that empty a database (migrate:fresh, migrate:refresh,
 * migrate:reset, migrate:rollback, db:wipe) off a site's own database
 * (issue 792): they run only on a test or benchmark database, or once with
 * TEME_ALLOW_DATABASE_WIPE set, and never in production unless so allowed.
 *
 * @package Teme
 * @since   1.6.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;

final class DestructiveCommands
{
    /** The commands Laravel's prohibition covers, as DB::prohibitDestructiveCommands() lists them. */
    public const array COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    /** The environment variable an operator sets, for one run, to let them through on purpose. */
    public const string ALLOW = 'TEME_ALLOW_DATABASE_WIPE';

    /**
     * Whether a database is one these commands may empty: a test database
     * (its name ends in `_test`, or `_test_` and a parallel process's number)
     * or the benchmark's (`_bench`).
     */
    public static function disposable(string $database): bool
    {
        return preg_match('/_(test(_\d+)?|bench)$/', $database) === 1;
    }

    /**
     * Whether the commands are refused for this database: always unless
     * allowed, and on any database that isn't disposable or in production.
     */
    public static function refused(string $database, bool $production, bool $allowed): bool
    {
        return ! $allowed && ($production || ! self::disposable($database));
    }

    /**
     * The name of the database a connection's settings point at, a `url`
     * (DB_URL or DATABASE_URL) winning over `database`, read without
     * connecting.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function database(array $connection): string
    {
        return (string) ((new ConfigurationUrlParser)->parseConfiguration($connection)['database'] ?? '');
    }

    /**
     * Returns the name of the database a connection, or the default one,
     * would use.
     */
    public static function databaseOf(?string $connection = null): string
    {
        $name = $connection ?? (string) config('database.default');

        return self::database((array) config("database.connections.{$name}", []));
    }

    /**
     * Sets Laravel's prohibition for the default connection: called at boot,
     * so a command a command calls is covered too.
     */
    public static function guardDefault(bool $production): void
    {
        DB::prohibitDestructiveCommands(self::refused(self::databaseOf(), $production, self::allowed()));
    }

    /**
     * Before one of the commands runs on its own, judges the database it
     * names (`--database`, or the default) and, when refused, says why
     * before Laravel's own one-line warning.
     */
    public static function starting(CommandStarting $event, bool $production): void
    {
        if (! in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        $named = $event->input->getParameterOption('--database', null);
        $database = self::databaseOf(is_string($named) && $named !== '' ? $named : null);
        $refused = self::refused($database, $production, self::allowed());

        DB::prohibitDestructiveCommands($refused);

        if ($refused) {
            $event->output->writeln('<error> '.self::refusal($database).' </error>');
        }
    }

    /**
     * The sentence a refused command prints.
     */
    public static function refusal(string $database): string
    {
        return sprintf(
            'Teme refused to empty the database "%s": this command drops tables or rolls migrations back, and that database isn\'t a test database (its name doesn\'t end in "_test"), so it is probably a site with real content. If you really mean to empty it, take a backup, then run the command again with %s=1 set for that one run.',
            $database,
            self::ALLOW,
        );
    }

    /**
     * Whether the operator allowed these commands for this run.
     */
    private static function allowed(): bool
    {
        return (bool) config('teme.allow_database_wipe', false);
    }
}
