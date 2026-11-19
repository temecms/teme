<?php

/**
 * The environment of a program Teme starts (I-366): what it must not
 * inherit from Teme's own (every RESTIC_, AWS_ and PG variable, so a
 * server's stray RESTIC_PASSWORD_FILE or AWS_PROFILE can't change what
 * restic opens, nor a PGPASSWORD what pg_dump connects with), and which of
 * the variables Teme gives it are secrets, by their names. Symfony's
 * Process leaves out of the child any variable given as false.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

final class ChildEnvironment
{
    private const string INHERITED = '/^(RESTIC_|AWS_|PG)/';

    /** A name that says its value is a secret. */
    private const string SECRET = '/PASSWORD|SECRET|TOKEN|KEY/';

    /**
     * Each such variable Teme was started with or has set, as false.
     *
     * @return array<string, false>
     */
    public static function withoutInherited(): array
    {
        $left = [];

        // Symfony's Process gives the child these three sources' variables.
        foreach (array_keys([...getenv(), ...$_ENV, ...$_SERVER]) as $name) {
            if (is_string($name) && preg_match(self::INHERITED, $name) === 1) {
                $left[$name] = false;
            }
        }

        return $left;
    }

    /**
     * The values no message may show: those of the variables whose names
     * hold PASSWORD, SECRET, TOKEN or KEY. A host, a user, a port, a
     * database's name or a region isn't one, and stays readable.
     *
     * @param  array<string, string>  $variables
     *
     * @return list<string>
     */
    public static function secretValues(array $variables): array
    {
        $secrets = [];

        foreach ($variables as $name => $value) {
            if ($value !== '' && preg_match(self::SECRET, (string) $name) === 1) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }
}
