<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use Illuminate\Contracts\Config\Repository;
use SensitiveParameter;

/**
 * What the site's database address must look like, and what a DATABASE_URL holds (I-375). A host that is really
 * an address, or a URL that can't be read, is named in a sentence that never repeats either, since both carry
 * the password.
 */
final class DatabaseAddress
{
    private const array SCHEMES = ['postgres', 'postgresql', 'pgsql'];

    /**
     * Each thing wrong with the database connection's address, one sentence each; empty when nothing is.
     *
     * @return list<string>
     */
    public static function problems(Repository $config): array
    {
        $problems = [];
        $host = $config->get('database.connections.pgsql.host');
        $url = $config->get('database.connections.pgsql.url');

        if (is_string($host) && preg_match('~://|@|\?|^[^/]+/~', $host) === 1) {
            $problems[] = 'DB_HOST holds an address, not a host name. Set DB_HOST to the server\'s name alone, such as db.internal, and give the rest in DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD, or give the whole address as DATABASE_URL.';
        }

        if (is_string($url) && $url !== '' && self::parts($url) === null) {
            $problems[] = 'DATABASE_URL isn\'t a PostgreSQL address Teme can read. Write it as postgresql://user:password@host:5432/database, with any special character in the password written as %XX.';
        }

        return $problems;
    }

    /**
     * The connection settings a PostgreSQL address holds, decoded, or null when it isn't one Teme can read.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}|null
     */
    public static function parts(#[SensitiveParameter] string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), self::SCHEMES, true)) {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));

        if ($host === '' || $database === '' || str_contains($database, '/')) {
            return null;
        }

        return [
            'host' => trim($host, '[]'),
            'port' => (string) ($parts['port'] ?? '5432'),
            'database' => $database,
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }
}
