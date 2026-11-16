<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Addresses\Paths;
use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The static-file driver's plain copies of stored pages, which the web
 * server serves itself with no PHP at all (I-086): a page answered 200 with
 * nothing but HTML, at the site's own address with no query, as a file
 * named by its address, `/about/` as `about/index.html`, so nginx or Caddy
 * finds it from the address alone. `page_cache_files` knows every file: a
 * row is written before its file and deleted only after it, by the prune,
 * so nothing lists the folder. A file's row expires EARLY seconds before its
 * page does.
 */
final readonly class StaticPages
{
    /**
     * Seconds before its page's life ends that a file's row expires. The
     * prune, every minute, deletes the file at least a minute before the
     * page's life or clock cap ends, and a CDN in front that fetched it keeps
     * the answer a minute at most (s-maxage=60, the web server's settings),
     * so neither serves it past the page's life (D7). A page with no more
     * than this left gets no file: PHP serves it from the store, as it
     * serves the last two minutes of every page.
     */
    public const int EARLY = 120;

    /** What a file's name adds to its address: `/about/` is `about/index.html`. */
    public const string INDEX = 'index.html';

    /** Rows taken per statement. */
    private const int BATCH = 500;

    /** `page_cache_files`' columns, in the order a row's values are given, as FilePageStore writes its rows. */
    private const array COLUMNS = ['entry', 'path', 'expires_at'];

    /**
     * The headers a page may carry and still be a file: its HTML's type,
     * and the variation by encoding the web server adds itself (D2).
     */
    private const array PLAIN = ['content-type' => ['text/html', 'text/html;charset=utf-8'], 'vary' => ['accept-encoding'], 'content-language' => ['en', 'en-gb', 'en-ng', 'en-us', 'ar', 'ca', 'cs', 'da', 'de', 'el', 'es', 'et', 'eu', 'fi', 'fr', 'ga', 'ha', 'hi', 'hu', 'hy', 'id', 'ig', 'it', 'ja', 'lt', 'nb', 'ne', 'nl', 'pcm', 'pl', 'pt', 'pt-br', 'ro', 'ru', 'sr', 'sv', 'sw', 'ta', 'tr', 'uk', 'yi', 'yo']];

    /**
     * The page's own content security policy and where its reports go, which
     * a file may carry whatever their value (I-3375): an enforced policy goes
     * into the file as a <meta>; report-only and the endpoints go nowhere.
     */
    private const array POLICY = ['content-security-policy', 'content-security-policy-report-only', 'reporting-endpoints'];

    public function __construct(
        private string $root,
        private Filesystem $files,
        private CdnPurges $cdn,
    ) {}

    /**
     * Whether this page gets a file (D2): it answered 200 with nothing but
     * HTML in UTF-8, at the site's own address with no query, on a path the
     * web server sees as Teme keyed it and the router doesn't keep for
     * itself, with more than EARLY seconds left. A page with any other
     * header, such as X-Robots-Tag, stays PHP's: the web server would drop it;
     * so does one with an enforced policy and no charset tag to follow.
     */
    public static function wanted(PageEntry $entry): bool
    {
        $path = self::pathOf($entry->key);

        return $entry->status === 200
            && $path !== null
            && self::nameFor($path) !== null
            && ! self::reserved($path)
            && self::plain($entry->headers)
            && $entry->expiresAt - self::EARLY > now()->getTimestamp()
            && self::fileOf($entry->body, $entry->headers) !== null;
    }

    /**
     * The path of the address this key answers, when a file could answer
     * it: the site's own scheme and host, APP_URL's, and no query. Null for
     * any other key.
     */
    public static function pathOf(string $key): ?string
    {
        $origin = self::origin();

        if ($origin === '' || ! str_starts_with($key, $origin.'/')) {
            return null;
        }

        $path = substr($key, strlen($origin));

        return str_contains($path, '?') ? null : $path;
    }

    /**
     * APP_URL's scheme and host, lower-cased, with its port when it isn't
     * the scheme's own: how the key of every page of the site begins
     * (PageKey::of()). Empty when APP_URL names no scheme or host.
     */
    public static function origin(): string
    {
        $url = parse_url((string) config('app.url'));

        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            return '';
        }

        $scheme = strtolower($url['scheme']);
        $port = $url['port'] ?? null;
        $ownPort = $port === null || ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme.'://'.strtolower($url['host']).($ownPort ? '' : ':'.$port);
    }

    /**
     * The name, under the folder, of the file the web server serves for this
     * path: the path decoded, as nginx's $uri and Caddy's {path} give it,
     * with INDEX after it, so the name keeps the path's case and whether it
     * ends in a slash (D3). Null for a path the web server would see
     * otherwise, or a file system couldn't name: an empty, dot or hidden
     * segment, a control character or backslash, text that isn't UTF-8, or
     * a segment too long for a file's name.
     */
    public static function nameFor(string $path): ?string
    {
        $decoded = rawurldecode($path);

        if (! str_starts_with($decoded, '/') || preg_match('/[\x00-\x1F\x7F\\\\]/', $decoded) === 1 || ! mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        $segments = explode('/', substr($decoded, 1));
        $last = count($segments) - 1;

        foreach ($segments as $position => $segment) {
            if (($segment === '' && $position !== $last) || str_starts_with($segment, '.') || strlen($segment) > 255 - strlen(self::INDEX)) {
                return null;
            }
        }

        return substr($decoded, 1).self::INDEX;
    }

    /**
     * Whether the router answers this path itself (Paths::RESERVED): never a
     * file there, whatever asked for it.
     */
    public static function reserved(string $path): bool
    {
        return in_array(explode('/', ltrim(rawurldecode($path), '/'))[0], Paths::RESERVED, true);
    }

    /**
     * Notes the file this page is about to get, before it exists, so the
     * table knows every file (D4), and says whether it gets one. Its row
     * expires EARLY seconds before the page; the page stored again moves it.
     */
    public function record(string $hash, PageEntry $entry): bool
    {
        $path = self::pathOf($entry->key);

        if ($path === null || ! self::wanted($entry)) {
            return false;
        }

        DB::table('page_cache_files')->upsert(
            [array_combine(self::COLUMNS, [$hash, $path, self::at($entry->expiresAt - self::EARLY)])],
            ['entry'],
            ['expires_at'],
        );

        return true;
    }

    /**
     * Writes the page's file for the web server. A file that can't be
     * written is reported (D15): the page is stored, and PHP serves it.
     */
    public function write(PageEntry $entry): void
    {
        try {
            $this->place((string) self::pathOf($entry->key), (string) self::fileOf($entry->body, $entry->headers));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * A stored page's HTML as its file (I-3375): with an enforced policy, that
     * policy in a <meta> right after the shell's charset, before anything it
     * governs; the body as it is with none, or with one that only reports.
     * Null for a page with an enforced policy and no charset tag to follow,
     * which PHP keeps serving with its header.
     *
     * @param  array<string, list<string>>  $headers
     */
    public static function fileOf(string $body, array $headers): ?string
    {
        $policy = null;

        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) === 'content-security-policy') {
                $policy = implode(', ', $values);
            }
        }

        if ($policy === null) {
            return $body;
        }

        $charset = '<meta charset="utf-8">';
        $at = strpos($body, $charset);

        if ($at === false) {
            return null;
        }

        $at += strlen($charset);

        return substr($body, 0, $at).'<meta http-equiv="Content-Security-Policy" content="'.e(PagePolicy::forMeta($policy)).'">'.substr($body, $at);
    }

    /**
     * Writes the file the web server serves at this path, whole beside the
     * old one and renamed over it (Filesystem::replace()), so it serves the
     * old page or the new one, never half of either, and returns its name
     * under the folder. Throws when the path can't be a file's, or the file
     * can't be written.
     */
    public function place(string $path, string $html): string
    {
        $name = self::nameFor($path) ?? throw new InvalidArgumentException("The web server can't serve a file at [{$path}].");
        $file = $this->root.'/'.$name;

        $this->files->ensureDirectoryExists(dirname($file));
        $this->files->replace($file, $html);

        return $name;
    }

    /**
     * Deletes the file this key's page had, if any: the race guard's undo,
     * and a page stored again that gets none, such as a "not found" now at
     * an article's address. Its row stays for the prune.
     */
    public function forget(string $key): void
    {
        $path = self::pathOf($key);

        if ($path !== null) {
            $this->discardAll([$path]);
        }
    }

    /**
     * Deletes the files of these stored pages, by their entries, the file
     * store's batch as it removes their rows or sets them aside. Their rows
     * stay for the prune.
     *
     * @param  list<string>  $hashes
     */
    public function remove(array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        $this->discardAll(DB::table('page_cache_files')->whereIn('entry', $hashes)->pluck('path')->map(fn (mixed $path): string => (string) $path)->values()->all());
    }

    /**
     * Deletes every file the table knows, BATCH rows at a time, found by
     * their rows, never by listing the folder: a flush, `*`, whose purge at
     * the CDN is the whole site's. The rows stay for the prune.
     */
    public function flush(): void
    {
        $after = '';

        do {
            $rows = DB::table('page_cache_files')->where('entry', '>', $after)->orderBy('entry')->limit(self::BATCH)->get(['entry', 'path']);

            foreach ($rows as $row) {
                $this->discard((string) $row->path);
                $after = (string) $row->entry;
            }
        } while ($rows->count() === self::BATCH);
    }

    /**
     * Deletes the files whose rows' time is up, BATCH at a time, then those
     * rows still up: a page stored again meanwhile keeps its new row, and at
     * worst loses its file, which its next reader's page writes again.
     */
    public function prune(int $now): void
    {
        $at = self::at($now);

        do {
            $rows = DB::table('page_cache_files')->where('expires_at', '<=', $at)->orderBy('expires_at')->limit(self::BATCH)->get(['entry', 'path']);

            // Idle, the one statement a minute.
            if ($rows->isEmpty()) {
                return;
            }

            $this->discardAll($rows->map(fn (object $row): string => (string) $row->path)->values()->all());

            DB::table('page_cache_files')
                ->whereIn('entry', $rows->map(fn (object $row): string => (string) $row->entry)->values()->all())
                ->where('expires_at', '<=', $at)
                ->delete();
        } while ($rows->count() === self::BATCH);
    }

    /**
     * Deletes every file the table knows, and its row, BATCH at a time, and
     * returns how many files there were: the static-file driver is off, so
     * nothing writes a file, and nothing else would remove one the web server
     * may still serve (D12).
     */
    public function retire(): int
    {
        $retired = 0;

        do {
            $rows = DB::table('page_cache_files')->orderBy('entry')->limit(self::BATCH)->get(['entry', 'path']);

            // Nothing left, as every minute once it is done: one statement.
            if ($rows->isEmpty()) {
                return $retired;
            }

            foreach ($rows as $row) {
                $retired += $this->discard((string) $row->path) ? 1 : 0;
            }

            DB::table('page_cache_files')->whereIn('entry', $rows->map(fn (object $row): string => (string) $row->entry)->values()->all())->delete();
        } while ($rows->count() === self::BATCH);

        return $retired;
    }

    /**
     * Deletes the files for these paths, and queues, for the CDN in front,
     * the addresses of those there were: an answer the web server gave from
     * a file carries no Cache-Tag (D10). A misconfigured CDN is reported,
     * never thrown, so every file of the batch still goes (D15).
     *
     * @param  list<string>  $paths
     */
    private function discardAll(array $paths): void
    {
        $gone = [];

        foreach ($paths as $path) {
            if ($this->discard($path)) {
                $gone[] = $path;
            }
        }

        try {
            $this->cdn->queueAddresses($gone);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * Deletes the file for this path, and says whether there was one. A file
     * that is there and can't be deleted is reported (D15): the web server
     * serves it until the prune can, when its row's time is up.
     */
    public function discard(string $path): bool
    {
        $name = self::nameFor($path);

        if ($name === null) {
            return false;
        }

        $file = $this->root.'/'.$name;

        if ($this->files->delete($file)) {
            return true;
        }

        if (is_file($file)) {
            report(new RuntimeException("The page cache couldn't delete {$file}; the web server serves it until the prune can."));
        }

        return false;
    }

    /**
     * Deletes the file for this path and the folders it leaves empty, up to
     * the static folder itself: the self-test's probe, which no reader asked
     * for. Each folder is removed on its own, only when empty, and none is
     * listed.
     */
    public function discardProbe(string $path): void
    {
        $name = self::nameFor($path);

        if ($name === null) {
            return;
        }

        $this->files->delete($this->root.'/'.$name);

        for ($folder = dirname($name); $folder !== '.' && $folder !== ''; $folder = dirname($folder)) {
            // A folder still holding another page's file isn't empty, and stays.
            if (! @rmdir($this->root.'/'.$folder)) {
                break;
            }
        }
    }

    /**
     * Whether a page's headers are only what the web server sends with a
     * file anyway (PLAIN), the page's own policy (POLICY), or Teme's own
     * X-Teme- headers, which only a page outside production carries.
     *
     * @param  array<string, list<string>>  $headers
     */
    private static function plain(array $headers): bool
    {
        foreach ($headers as $name => $values) {
            $name = strtolower($name);
            $value = strtolower(str_replace(' ', '', implode(',', $values)));

            if (! str_starts_with($name, 'x-teme-') && ! in_array($name, self::POLICY, true) && ! in_array($value, self::PLAIN[$name] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A time as PostgreSQL takes it, in UTC.
     */
    private static function at(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
