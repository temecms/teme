<?php

/**
 * What a site holds and what its public addresses answer, in lines two
 * servers can compare after a restore (I-385): each table's rows, and each
 * page asked as a reader would, drawn with the page cache off and the
 * site's own host made neutral. It writes nothing itself; a page it asks
 * writes what a reader's does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Addresses\Addresses;
use App\Domain\Backups\BackupTables;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class SiteFingerprint
{
    /** The addresses every fingerprint asks first. */
    public const array FIXED = ['/', '/feed/', '/sitemap.xml', '/robots.txt'];

    /** What stands for the site's own host in a page and in a redirect. */
    public const string HOST = '{host}';

    /**
     * Tables the server's own work writes, which a restore doesn't promise
     * to match later: printed, never compared. Every table a backup keeps
     * as structure only, and every backups table, are among them too.
     */
    public const array ACTIVITY = ['bulk_runs', 'deploy_runs', 'heartbeats', 'inbox_items', 'not_found_log', 'password_reset_tokens', 'security_events', 'teme_releases', 'teme_updates', 'tracked_jobs', 'update_checks'];

    /** The statuses that send a reader on. */
    private const array ONWARD = [301, 302, 303, 307, 308];

    /**
     * The newest migration this database has run, or null when it has run none.
     */
    public function migration(): ?string
    {
        $name = DB::table('migrations')->max('migration');

        return is_string($name) ? $name : null;
    }

    /**
     * Every table of the site's schema, partitions left out, in byte order
     * of name, each with its rows and whether it is the server's own work.
     *
     * @return list<array{name: string, rows: int, activity: bool}>
     */
    public function tables(): array
    {
        $tables = [];

        $names = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema()
              AND c.relkind IN ('r', 'p')
              AND NOT c.relispartition
            ORDER BY c.relname COLLATE "C"
            SQL);

        foreach ($names as $row) {
            $name = (string) $row->name;
            $counted = DB::selectOne('SELECT count(*) AS counted FROM "'.str_replace('"', '""', $name).'"');
            $tables[] = ['name' => $name, 'rows' => (int) $counted->counted, 'activity' => self::isActivity($name)];
        }

        return $tables;
    }

    /**
     * The site's own addresses to ask after the fixed ones: up to $count,
     * current and old alike, in the order of their path's MD5, which is
     * the same on every server holding the same rows.
     *
     * @return list<string>
     */
    public function sample(int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            SELECT p.path, c.type
            FROM paths p
            LEFT JOIN contents c ON p.target_type = 'content' AND p.target_id = c.id::text
            ORDER BY md5(p.path) COLLATE "C", p.path
            LIMIT ?
            SQL, [$count]);

        // A space would split a fingerprint's line, so it is written, and asked, as %20.
        return array_values(array_map(
            fn (object $row): string => str_replace(' ', '%20', Addresses::toUrl((string) $row->path, $row->type === null ? null : (string) $row->type)),
            $rows,
        ));
    }

    /**
     * What this site answers at a site-relative address, asked as a reader
     * would, with the page cache off: the status, then where a redirect
     * sends the reader (a path when it stays on this site) or the SHA-256
     * of the body, with the site's own host made neutral in either.
     */
    public function answer(string $address): string
    {
        $app = app();
        $app->forgetScopedInstances();
        $previous = $app->bound('request') ? $app->make('request') : null;
        $cached = config('page_cache.enabled');
        $site = rtrim((string) config('app.url'), '/');
        $host = self::authority($site);
        $kernel = $app->make(HttpKernel::class);
        $request = Request::create($site.$address);

        // Drawn every time, so two servers compare pages, not their caches.
        config(['page_cache.enabled' => false]);

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            $status = $response->getStatusCode();

            if (in_array($status, self::ONWARD, true)) {
                $location = (string) $response->headers->get('Location');
                $onward = match (true) {
                    str_starts_with($location, $site.'/') => substr($location, strlen($site)),
                    str_starts_with($location, '/') => $location,
                    default => str_replace($host, self::HOST, $location),
                };

                return $status.' '.$onward;
            }

            return $status.' '.hash('sha256', str_replace($host, self::HOST, (string) $response->getContent()));
        } finally {
            config(['page_cache.enabled' => $cached]);

            // The process's own request again, for whatever runs after.
            if ($previous instanceof Request) {
                $app->instance('request', $previous);
            }
        }
    }

    /**
     * The site's host, with its port when the address names one.
     */
    public static function authority(string $site): string
    {
        $parts = parse_url($site);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return isset($parts['port']) ? $host.':'.$parts['port'] : $host;
    }

    private static function isActivity(string $table): bool
    {
        return str_starts_with($table, 'backup_') || in_array($table, [...BackupTables::STRUCTURE_ONLY, ...self::ACTIVITY], true);
    }
}
