<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Illuminate\Cache\PhpRedisLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * The page store for several servers (I-265): pages in Valkey, each a hash
 * of its JSON line and its HTML, found through a set per tag as MilliCache
 * keeps them (docs/studies/page-cache.md §3.8), under a generation that
 * flush() moves on, never KEYS. Every command is one of the fixed Lua
 * scripts below, run whole in one round trip; a page's values travel as a
 * script's arguments, never its text, and the client passes them as
 * given, never serialized (rule 4).
 */
final readonly class RedisPageStore implements PageStore
{
    /** Members of a tag's set, or expired pages, one script takes: a few milliseconds of Valkey's time. */
    private const int BATCH = 500;

    /**
     * Seconds Valkey keeps a page past its life, so it can still be given as
     * a stale copy while one request draws it afresh: as long as a page may
     * take to draw. prune() removes it at its life's end, every minute, as
     * the file store's prune removes an expired file; this only bounds a
     * scheduler that stopped.
     */
    private const int GRACE = PageCache::RENDER_WINDOW;

    /**
     * Seconds a page's list of its tags outlives the page, so prune() can
     * still take it out of each tag's set after Valkey let the page go: a
     * scheduler stopped for up to a day.
     */
    private const int TAGS_KEPT = 86400;

    /** The kinds of key a page is kept under: the page, and the copy a change set aside (slice 1.7c). */
    private const string FRESH = 'page';

    private const string SET_ASIDE = 'stale';

    /**
     * Every script's first line: the keys of the current generation, built
     * from KEYS[1], the store's prefix, given as a key so the connection's
     * own prefix reaches every key a script builds from it.
     */
    private const string CURRENT = <<<'LUA'
        local ns = KEYS[1] .. (redis.call('GET', KEYS[1] .. 'generation') or '0') .. ':'

        LUA;

    /**
     * Removes one page, its copy set aside, its list of tags, its place in
     * each tag's set and its expiry; 1 when there was a page or a copy.
     */
    private const string REMOVE_ONE = <<<'LUA'
        local function remove(hash)
          local own = ns .. 'tags:' .. hash
          for _, name in ipairs(redis.call('SMEMBERS', own)) do
            redis.call('SREM', ns .. 'tag:' .. name, hash)
          end
          redis.call('ZREM', ns .. 'expiries', hash)
          redis.call('DEL', own)
          return redis.call('DEL', ns .. 'page:' .. hash, ns .. 'stale:' .. hash) > 0 and 1 or 0
        end

        LUA;

    /** A page or its copy: its JSON line and HTML. ARGV: FRESH or SET_ASIDE, the page's hash. */
    private const string GET = self::CURRENT.<<<'LUA'
        return redis.call('HMGET', ns .. ARGV[1] .. ':' .. ARGV[2], 'meta', 'body')
        LUA;

    /**
     * Stores a page. ARGV: its hash, JSON line and HTML, the seconds Valkey
     * keeps it, its expiry for prune(), TAGS_KEPT, then its tags. A tag it
     * no longer has loses it from its set, and the copy set aside goes; each
     * tag's set lives at least as long as the page.
     */
    private const string PUT = self::CURRENT.<<<'LUA'
        local hash, ttl = ARGV[1], tonumber(ARGV[4])
        local kept = ttl + tonumber(ARGV[6])
        local own, page = ns .. 'tags:' .. hash, ns .. 'page:' .. hash
        local wanted = {}
        for i = 7, #ARGV do wanted[ARGV[i]] = true end
        for _, name in ipairs(redis.call('SMEMBERS', own)) do
          if not wanted[name] then redis.call('SREM', ns .. 'tag:' .. name, hash) end
        end
        redis.call('DEL', own, ns .. 'stale:' .. hash)
        redis.call('HSET', page, 'meta', ARGV[2], 'body', ARGV[3])
        redis.call('EXPIRE', page, ttl)
        for i = 7, #ARGV do
          local set = ns .. 'tag:' .. ARGV[i]
          redis.call('SADD', set, hash)
          redis.call('SADD', own, ARGV[i])
          if redis.call('TTL', set) < ttl then redis.call('EXPIRE', set, ttl) end
        end
        redis.call('EXPIRE', own, kept)
        local expiries = ns .. 'expiries'
        redis.call('ZADD', expiries, ARGV[5], hash)
        if redis.call('TTL', expiries) < kept then redis.call('EXPIRE', expiries, kept) end
        return 1
        LUA;

    /**
     * Sets aside the pages in one batch of a tag's set. ARGV: the tag, the
     * cursor, BATCH, the moment the copies are kept until, and the seconds
     * that is from now. Each page is renamed to its copy, whole; its places
     * in the tags' sets stay, so a purge of any of its tags still finds it,
     * and its expiry moves to that moment, so prune() removes it then.
     * Returns the next cursor and how many pages were set aside.
     */
    private const string MARK = self::CURRENT.<<<'LUA'
        local scan = redis.call('SSCAN', ns .. 'tag:' .. ARGV[1], ARGV[2], 'COUNT', ARGV[3])
        local marked, keep = 0, tonumber(ARGV[5])
        for _, hash in ipairs(scan[2]) do
          local page = ns .. 'page:' .. hash
          if redis.call('EXISTS', page) == 1 then
            local copy = ns .. 'stale:' .. hash
            redis.call('RENAME', page, copy)
            if redis.call('TTL', copy) > keep then redis.call('EXPIRE', copy, keep) end
            redis.call('ZADD', ns .. 'expiries', 'LT', ARGV[4], hash)
            marked = marked + 1
          end
        end
        return {scan[1], marked}
        LUA;

    /**
     * Removes the pages in one batch of a tag's set. ARGV: the tag, the
     * cursor, BATCH. Returns the next cursor and how many pages went.
     */
    private const string REMOVE = self::CURRENT.self::REMOVE_ONE.<<<'LUA'
        local set = ns .. 'tag:' .. ARGV[1]
        local scan = redis.call('SSCAN', set, ARGV[2], 'COUNT', ARGV[3])
        local removed = 0
        for _, hash in ipairs(scan[2]) do
          redis.call('SREM', set, hash)
          removed = removed + remove(hash)
        end
        return {scan[1], removed}
        LUA;

    /** Removes one page. ARGV: its hash. */
    private const string FORGET = self::CURRENT.self::REMOVE_ONE.<<<'LUA'
        return remove(ARGV[1])
        LUA;

    /** Moves the generation on, and returns how many pages the old one held. */
    private const string FLUSH = self::CURRENT.<<<'LUA'
        local count = redis.call('ZCARD', ns .. 'expiries')
        redis.call('INCR', KEYS[1] .. 'generation')
        return count
        LUA;

    /**
     * Removes up to BATCH pages whose life ended. ARGV: now, BATCH. Returns
     * how many were due, and how many pages or copies went.
     */
    private const string PRUNE = self::CURRENT.self::REMOVE_ONE.<<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', ns .. 'expiries', '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
        local removed = 0
        for _, hash in ipairs(due) do removed = removed + remove(hash) end
        return {#due, removed}
        LUA;

    /**
     * The JSON lines of up to ARGV[1] pages, or copies set aside, carrying
     * any of the tags after it: one SSCAN of each tag's set, never read
     * whole.
     */
    private const string KEYS_OF = self::CURRENT.<<<'LUA'
        local limit, found, seen = tonumber(ARGV[1]), {}, {}
        for i = 2, #ARGV do
          if #found >= limit then break end
          local scan = redis.call('SSCAN', ns .. 'tag:' .. ARGV[i], '0', 'COUNT', limit)
          for _, hash in ipairs(scan[2]) do
            if #found < limit and not seen[hash] then
              seen[hash] = true
              local meta = redis.call('HGET', ns .. 'page:' .. hash, 'meta') or redis.call('HGET', ns .. 'stale:' .. hash, 'meta')
              if meta then table.insert(found, meta) end
            end
          end
        end
        return found
        LUA;

    public function __construct(
        private RedisFactory $redis,
        private string $connection,
        private string $prefix,
        private RedisOutage $outage = new RedisOutage,
    ) {}

    public function get(PageKey $key): ?PageEntry
    {
        return $this->read(self::FRESH, $key);
    }

    public function stale(PageKey $key): ?PageEntry
    {
        return $this->read(self::SET_ASIDE, $key);
    }

    /**
     * The page or copy of this kind kept for this key, or null when there's none.
     */
    private function read(string $kind, PageKey $key): ?PageEntry
    {
        $found = $this->run(self::GET, $kind, self::hashOf($key->url));
        $line = is_array($found) ? ($found[0] ?? null) : null;
        $body = is_array($found) ? ($found[1] ?? null) : null;

        return is_string($line) && is_string($body) ? PageEntry::fromStored($line, $body, $key->url) : null;
    }

    public function put(PageEntry $entry): void
    {
        if ($entry->tags === []) {
            throw new InvalidArgumentException('A stored page needs a tag: its tag sets are how it is found again.');
        }

        $this->run(
            self::PUT,
            self::hashOf($entry->key),
            $entry->storedLine(),
            $entry->body,
            max(1, $entry->expiresAt - now()->getTimestamp()) + self::GRACE,
            $entry->expiresAt,
            self::TAGS_KEPT,
            ...$entry->tags,
        );
    }

    public function purge(array $tags): int
    {
        if (in_array(PageTags::EVERYTHING, $tags, true)) {
            return $this->flush();
        }

        $removed = 0;

        foreach (array_unique($tags) as $tag) {
            $removed += $this->walk(self::REMOVE, $tag);
        }

        return $removed;
    }

    public function markStale(array $tags, int $until): int
    {
        $keep = max(1, $until - now()->getTimestamp());
        $marked = 0;

        foreach (array_unique($tags) as $tag) {
            $marked += $this->walk(self::MARK, $tag, $until, $keep);
        }

        return $marked;
    }

    public function forget(string $key): void
    {
        $this->run(self::FORGET, self::hashOf($key));
    }

    public function flush(): int
    {
        return (int) $this->run(self::FLUSH);
    }

    public function prune(int $now): int
    {
        $removed = 0;

        do {
            $result = $this->run(self::PRUNE, $now, self::BATCH);
            $due = is_array($result) ? (int) ($result[0] ?? 0) : 0;
            $removed += is_array($result) ? (int) ($result[1] ?? 0) : 0;
        } while ($due === self::BATCH);

        return $removed;
    }

    public function keys(array $tags, int $limit): array
    {
        if ($tags === [] || $limit < 1) {
            return [];
        }

        $found = $this->run(self::KEYS_OF, $limit, ...array_values(array_unique($tags)));
        $keys = [];

        foreach (is_array($found) ? $found : [] as $line) {
            $meta = is_string($line) ? json_decode($line, true) : null;

            if (! is_array($meta)) {
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
     * The lock in Valkey, beside the pages: one round trip to take it, its
     * value a random owner, never serialized; outside every generation, so
     * a flush frees no lock.
     */
    public function lock(PageKey $key, int $seconds): Lock
    {
        return new PhpRedisLock($this->connection(), $this->prefix.$key->lockName(), $seconds);
    }

    /**
     * Walks a tag's set with this script, a batch at a time by SSCAN's
     * cursor, so no batch reads the set whole however many pages carry the
     * tag, and returns how many pages the walk removed or set aside.
     */
    private function walk(string $script, string $tag, int ...$more): int
    {
        $cursor = '0';
        $done = 0;

        do {
            $result = $this->run($script, $tag, $cursor, self::BATCH, ...$more);
            $cursor = is_array($result) ? (string) ($result[0] ?? '0') : '0';
            $done += is_array($result) ? (int) ($result[1] ?? 0) : 0;
        } while ($cursor !== '0');

        return $done;
    }

    /**
     * Runs one of the scripts, the store's prefix its one key.
     */
    private function run(string $script, string|int ...$arguments): mixed
    {
        return $this->connection()->eval($script, 1, $this->prefix, ...$arguments);
    }

    /**
     * The page cache's Redis connection, made the first time it is used, so
     * a Valkey that is down is a miss, reported, never a page that fails. A
     * connection that could not be made is not tried again in the same
     * request: the store is down for the rest of it, and each ask is
     * refused with that failure, so a Valkey that drops packets costs one
     * connect timeout, not one for each time the store is asked.
     */
    private function connection(): PhpRedisConnection
    {
        $failure = $this->outage->failure();

        if ($failure !== null) {
            throw $failure;
        }

        try {
            $connection = $this->redis->connection($this->connection);
        } catch (Throwable $failure) {
            $this->outage->noteFailure($failure);

            throw $failure;
        }

        if (! $connection instanceof PhpRedisConnection) {
            throw new LogicException('The page cache\'s Redis store needs the phpredis extension: set REDIS_CLIENT=phpredis.');
        }

        return $connection;
    }

    private static function hashOf(string $url): string
    {
        return hash('sha256', $url);
    }
}
