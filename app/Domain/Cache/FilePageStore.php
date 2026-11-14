<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use ErrorException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The page store for one server, or several sharing one folder (I-265):
 * each page is a file named by the SHA-256 of its key, one line of JSON and
 * then its HTML, and `page_cache_tags` finds files by tag and by expiry, so
 * nothing lists the folder. A file is written before its rows and deleted
 * when they can't be, so the table knows every file. Its folder is named in
 * a small file, so a process removing pages from another folder can say so.
 * A change to a list it shows sets it aside, renamed, as a copy served
 * while it is drawn afresh. With the static-file driver, each page the web
 * server may serve gets a plain copy too (StaticPages, slice 1.7d″), written
 * after the page's rows and removed after them, batch by batch.
 */
final readonly class FilePageStore implements PageStore
{
    /** Pages flush() and prune() take from the table per statement. */
    private const int BATCH = 500;

    /**
     * `page_cache_tags`' columns, in the order a row's values are given: a
     * row written as an array keyed by the word tag reads, to
     * NoTypeLiteralsTest, as a type named in code (B-225).
     */
    private const array COLUMNS = ['entry', 'tag', 'expires_at'];

    /** The file of a stored page, and of the copy a change set aside (slice 1.7c). */
    private const string FRESH = '.page';

    private const string SET_ASIDE = '.stale';

    /** The file in the store's folder that holds its name. */
    private const string MARKER = '.teme-folder';

    /** Where the cache store keeps the name of the folder pages are stored in. */
    public const string FOLDER_KEY = 'page-cache.folder';

    /** Where a process that reached another folder says so, for the Status banner. */
    public const string UNSHARED_KEY = 'page-cache.unshared';

    public function __construct(
        private string $root,
        private Filesystem $files,
        private ?StaticPages $staticPages = null,
    ) {}

    public function get(PageKey $key): ?PageEntry
    {
        return $this->read($this->pathOf($key->url), $key->url);
    }

    public function stale(PageKey $key): ?PageEntry
    {
        return $this->read($this->pathFor(self::hashOf($key->url), self::SET_ASIDE), $key->url);
    }

    /**
     * The page one file holds for this key, or null when there's none to read.
     */
    private function read(string $path, string $url): ?PageEntry
    {
        $handle = self::open($path);

        if ($handle === null) {
            return null;
        }

        try {
            $line = (string) fgets($handle);
            $body = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return is_string($body) ? PageEntry::fromStored($line, $body, $url) : null;
    }

    public function put(PageEntry $entry): void
    {
        if ($entry->tags === []) {
            throw new InvalidArgumentException('A stored page needs a tag: its rows are how its file is found again.');
        }

        $this->markFolder();

        $hash = self::hashOf($entry->key);
        $path = $this->pathFor($hash);
        $staticPages = $this->staticPages;

        // The web server's copy is noted before anything is written, so its
        // table knows the file before it exists (slice 1.7d″).
        $copied = $staticPages !== null && $staticPages->record($hash, $entry);

        // Written whole beside the old file and renamed over it, so a reader
        // meets the old page or the new one, never half of either.
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->replace($path, $entry->storedLine()."\n".$entry->body);

        // The page stored again replaces the copy a change set aside.
        $this->files->delete($this->pathFor($hash, self::SET_ASIDE));

        try {
            $expiresAt = self::at($entry->expiresAt);

            // A tag the page no longer has would purge it for nothing.
            DB::table('page_cache_tags')->where('entry', $hash)->whereNotIn('tag', $entry->tags)->delete();
            DB::table('page_cache_tags')->upsert(
                array_map(fn (string $tag): array => array_combine(self::COLUMNS, [$hash, $tag, $expiresAt]), $entry->tags),
                ['entry', 'tag'],
                ['expires_at'],
            );
        } catch (Throwable $failure) {
            // A file the table doesn't know could never be flushed or pruned.
            $this->files->delete($path);

            throw $failure;
        }

        if ($staticPages === null) {
            return;
        }

        // Last, once the page's rows find it: its file, or none where it has
        // none now, such as a "not found" stored at an article's address.
        if ($copied) {
            $staticPages->write($entry);
        } else {
            $staticPages->forget($entry->key);
        }
    }

    public function flush(): int
    {
        $this->checkFolder();
        $removed = 0;

        do {
            $hashes = self::batch(DB::table('page_cache_tags'));

            // Rows before files: a page stored meanwhile may be left with rows
            // and no file, which is harmless, but never a file with no rows.
            DB::table('page_cache_tags')->whereIn('entry', $hashes)->delete();

            foreach ($hashes as $hash) {
                $this->files->delete([$this->pathFor($hash), $this->pathFor($hash, self::SET_ASIDE)]);
            }

            $removed += count($hashes);
        } while ($hashes !== []);

        // The web server's files, every one the table knows (slice 1.7d″).
        $this->staticPages?->flush();

        return $removed;
    }

    public function purge(array $tags): int
    {
        if (in_array(PageTags::EVERYTHING, $tags, true)) {
            return $this->flush();
        }

        $this->checkFolder();
        $removed = 0;

        foreach (array_chunk(array_values(array_unique($tags)), self::BATCH) as $chunk) {
            do {
                $hashes = self::batch(DB::table('page_cache_tags')->whereIn('tag', $chunk));

                // Every row of each page, not only the purged tag's, and rows
                // before files, as flush() takes them.
                DB::table('page_cache_tags')->whereIn('entry', $hashes)->delete();

                foreach ($hashes as $hash) {
                    $this->files->delete([$this->pathFor($hash), $this->pathFor($hash, self::SET_ASIDE)]);
                }

                // Their files for the web server, after their rows (slice 1.7d″, D5).
                $this->staticPages?->remove($hashes);

                $removed += count($hashes);
            } while ($hashes !== []);
        }

        return $removed;
    }

    public function markStale(array $tags, int $until): int
    {
        $this->checkFolder();
        $at = self::at($until);
        $marked = 0;

        foreach (array_chunk(array_values(array_unique($tags)), self::BATCH) as $chunk) {
            $after = '';

            do {
                // Each page carrying a tag, once, in order: a mark leaves the
                // rows, so they can't be taken again as purge() takes them.
                $hashes = DB::table('page_cache_tags')
                    ->whereIn('tag', $chunk)
                    ->where('entry', '>', $after)
                    ->distinct()
                    ->orderBy('entry')
                    ->limit(self::BATCH)
                    ->pluck('entry')->map(fn (mixed $hash): string => (string) $hash)->all();

                if ($hashes === []) {
                    break;
                }

                // Every row of each page, so a purge of any of its tags still
                // finds its copy, and prune() removes it once it is kept no longer.
                DB::table('page_cache_tags')->whereIn('entry', $hashes)->where('expires_at', '>', $at)->update(['expires_at' => $at]);

                foreach ($hashes as $hash) {
                    $marked += $this->setAside($hash) ? 1 : 0;
                }

                // A copy set aside is never a file the web server serves (slice 1.7d″, D6).
                $this->staticPages?->remove($hashes);

                $after = (string) end($hashes);
            } while (count($hashes) === self::BATCH);
        }

        return $marked;
    }

    /**
     * Moves the page's file aside as its stale copy, replacing any copy
     * there, and says whether there was a page to move. Renamed whole, so a
     * reader meets the page or the copy, never half of either.
     */
    private function setAside(string $hash): bool
    {
        try {
            return $this->files->move($this->pathFor($hash), $this->pathFor($hash, self::SET_ASIDE));
        } catch (ErrorException) {
            // Set aside already, removed, or never stored: rename() warns, and Laravel throws.
            return false;
        }
    }

    public function forget(string $key): void
    {
        $hash = self::hashOf($key);

        DB::table('page_cache_tags')->where('entry', $hash)->delete();
        $this->files->delete([$this->pathFor($hash), $this->pathFor($hash, self::SET_ASIDE)]);
        $this->staticPages?->forget($key);
    }

    /**
     * The lock from Laravel's default cache store, which every process
     * shares: `cache_locks` in the database where there is no Valkey
     * (slice 1.7c).
     */
    public function lock(PageKey $key, int $seconds): Lock
    {
        return Cache::lock($key->lockName(), $seconds);
    }

    /**
     * Names the folder pages are stored in, the first time one is, and
     * tells the cache store, which every process shares, so a process that
     * removes pages can tell whether it reaches the same folder. Each page
     * stored tells it again if the cache store has forgotten the name, as
     * `php artisan cache:clear` makes it. A marker that can't be read tells
     * nothing: an empty name would read as a mismatch to every process.
     */
    private function markFolder(): void
    {
        $marker = $this->root.'/'.self::MARKER;

        if (is_file($marker)) {
            $name = self::nameIn($marker);

            // php artisan cache:clear forgets the name, so tell the cache store again.
            if ($name !== '') {
                Cache::add(self::FOLDER_KEY, $name);
            }

            return;
        }

        $this->files->ensureDirectoryExists($this->root);
        $this->files->replace($marker, Str::random(32));

        // Read back: another process may have named it in the same instant.
        $name = self::nameIn($marker);

        if ($name !== '') {
            Cache::forever(self::FOLDER_KEY, $name);
        }
    }

    /**
     * Says, in the log and on the Status banner, when this process removes
     * pages from a folder other than the one they are stored in: their
     * files stay where the web server reads them, and are served until
     * they expire. It never throws: removing the rows is right either way.
     */
    private function checkFolder(): void
    {
        $stored = Cache::get(self::FOLDER_KEY);

        if (! is_string($stored) || self::nameIn($this->root.'/'.self::MARKER) === $stored) {
            return;
        }

        $problem = ['folder' => $this->root, 'host' => (string) gethostname(), 'at' => now()->toIso8601String()];

        Cache::put(self::UNSHARED_KEY, $problem, now()->addDay());
        Log::warning('The page cache removed pages from a folder they are not stored in. Give every process one shared folder (PAGE_CACHE_PATH).', $problem);
    }

    /**
     * The name the marker in $root holds, or '' when there is none to read:
     * what another process compares with the name the cache store was told
     * (I-480).
     */
    public static function folderNameAt(string $root): string
    {
        return self::nameIn($root.'/'.self::MARKER);
    }

    /**
     * The name a folder's marker holds, or '' when there is none to read.
     */
    private static function nameIn(string $marker): string
    {
        $handle = self::open($marker);

        if ($handle === null) {
            return '';
        }

        try {
            return trim((string) fgets($handle));
        } finally {
            fclose($handle);
        }
    }

    public function prune(int $now): int
    {
        $this->checkFolder();
        $at = self::at($now);
        $removed = 0;

        do {
            $hashes = self::batch(DB::table('page_cache_tags')->where('expires_at', '<=', $at));

            // Only rows still expired: a page stored again meanwhile keeps its new ones.
            DB::table('page_cache_tags')->whereIn('entry', $hashes)->where('expires_at', '<=', $at)->delete();

            foreach ($hashes as $hash) {
                $path = $this->pathFor($hash);
                $expiresAt = self::metaAt($path)['expiresAt'] ?? 0;

                // Its file too, unless the page was stored again, with a
                // later life, after its rows were read.
                if (! is_int($expiresAt) || $expiresAt <= $now) {
                    $this->files->delete($path);
                    $removed++;
                }

                // A copy set aside is kept only as long as its rows (markStale()).
                $this->files->delete($this->pathFor($hash, self::SET_ASIDE));
            }
        } while ($hashes !== []);

        // The web server's files go EARLY seconds before their pages (slice 1.7d″, D7).
        $this->staticPages?->prune($now);

        return $removed;
    }

    public function keys(array $tags, int $limit): array
    {
        if ($tags === [] || $limit < 1) {
            return [];
        }

        $hashes = DB::table('page_cache_tags')
            ->whereIn('tag', array_values(array_unique($tags)))
            ->distinct()
            ->limit($limit)
            ->pluck('entry')
            ->map(fn (mixed $hash): string => (string) $hash)
            ->values()
            ->all();
        $keys = [];

        foreach ($hashes as $hash) {
            // The page, or the copy a change set aside: its JSON line says its key and status.
            $meta = self::metaAt($this->pathFor($hash)) ?? self::metaAt($this->pathFor($hash, self::SET_ASIDE));

            if ($meta === null) {
                continue;
            }

            $key = $meta['key'] ?? null;

            if (($meta['status'] ?? null) === 200 && is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Where the page stored under this key lives: two levels of folders,
     * named from its hash, keep any one folder small.
     */
    public function pathOf(string $url): string
    {
        return $this->pathFor(self::hashOf($url));
    }

    private function pathFor(string $hash, string $suffix = self::FRESH): string
    {
        return $this->root.'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash.$suffix;
    }

    private static function hashOf(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * A time as PostgreSQL takes it, in UTC.
     */
    private static function at(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Up to BATCH stored pages among these rows, each by the hash that names its file.
     *
     * @return list<string>
     */
    private static function batch(Builder $rows): array
    {
        return $rows->distinct()->limit(self::BATCH)->pluck('entry')->map(fn (mixed $hash): string => (string) $hash)->values()->all();
    }

    /**
     * The file, open for reading, or null when it isn't there: a purge or a
     * prune may delete it between a lookup and a read, and Laravel turns
     * fopen()'s warning into an exception.
     *
     * @return resource|null
     */
    private static function open(string $path)
    {
        try {
            $handle = fopen($path, 'rb');
        } catch (ErrorException) {
            return null;
        }

        return $handle === false ? null : $handle;
    }

    /**
     * The JSON line at the head of a stored page, or null when there's none to read.
     *
     * @return array<mixed>|null
     */
    private static function metaAt(string $path): ?array
    {
        $handle = self::open($path);

        if ($handle === null) {
            return null;
        }

        try {
            $meta = json_decode((string) fgets($handle), true);
        } finally {
            fclose($handle);
        }

        return is_array($meta) ? $meta : null;
    }
}
