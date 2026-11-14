<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RedisPageStore;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

// No database trait: the store keeps nothing in PostgreSQL, and TestCase
// stops any query from a test without one (#14).

beforeEach(function (): void {
    $this->freezeSecond();
    $this->prefix = cacheValkey();
    $this->app->instance(PageStore::class, new RedisPageStore(app(Factory::class), 'page-cache', $this->prefix));
    $this->valkey = Redis::connection('page-cache');
});

afterEach(fn () => cacheOffValkey($this->prefix ?? null));

it('keeps a page as its JSON line and its HTML, strings no client serializes (rule 4)', function (): void {
    $entry = cachePage('/harbour/', '<p>The lifeboat crew</p>');
    $page = $this->prefix.'0:page:'.hash('sha256', 'http://localhost/harbour/');

    expect(json_decode((string) $this->valkey->hget($page, 'meta'), true))->toBe([
        'key' => 'http://localhost/harbour/',
        'status' => 200,
        'headers' => ['Content-Type' => ['text/html; charset=UTF-8']],
        'storedAt' => $entry->storedAt,
        'expiresAt' => $entry->storedAt + 60,
        'tags' => $entry->tags,
    ])
        ->and($this->valkey->hget($page, 'body'))->toBe('<p>The lifeboat crew</p>')
        ->and(cacheEntry('/harbour/'))->toEqual($entry);
});

it('keeps a page as JSON and HTML even on a connection told to serialize, since a script\'s arguments pass as given (rule 4)', function (): void {
    // phpredis's own serializer, Redis::SERIALIZER_PHP (1), on a connection of its own.
    config(['database.redis.serializing' => [...config('database.redis.page-cache'), 'options' => ['serializer' => 1]]]);
    $store = new RedisPageStore(new RedisManager(app(), 'phpredis', config('database.redis')), 'serializing', $this->prefix);
    $key = PageKey::of(Request::create('/harbour/')) ?? throw new LogicException('No key.');

    $store->put(new PageEntry($key->url, 200, [], '<p>Plain</p>', now()->getTimestamp(), now()->getTimestamp() + 60, [$key->urlTag()]));
    $page = $this->prefix.'0:page:'.hash('sha256', $key->url);

    expect((string) $this->valkey->hget($page, 'meta'))->toStartWith('{"key":"http://localhost/harbour/"')
        ->and($this->valkey->hget($page, 'body'))->toBe('<p>Plain</p>')
        ->and($store->get($key)?->body)->toBe('<p>Plain</p>');
});

it('finds a page only under its own address', function (): void {
    cachePage('/harbour/');

    expect(cacheEntry('/harbour/'))->not->toBeNull()
        ->and(cacheEntry('/Harbour/'))->toBeNull()
        ->and(cacheEntry('/harbour/?s=crew'))->toBeNull();
});

it('drops a tag a page no longer has from that tag\'s set when it is stored again', function (): void {
    $url = PageTags::url('/harbour/');
    $hash = hash('sha256', 'http://localhost/harbour/');
    cachePage('/harbour/', tags: [$url, 'list:post']);

    expect($this->valkey->sismember($this->prefix.'0:tag:list:post', $hash))->toBeTrue();

    cachePage('/harbour/', tags: [$url]);

    expect(cacheValkeyKeys($this->prefix))->not->toHaveKey('0:tag:list:post')
        ->and($this->valkey->smembers($this->prefix.'0:tags:'.$hash))->toBe([$url]);
});

it('refuses a page with no tag, which nothing could find again', function (): void {
    expect(fn () => cachePage('/harbour/', tags: []))->toThrow(InvalidArgumentException::class)
        ->and(cacheValkeyKeys($this->prefix))->toBe([]);
});

it('purges every page that carries any of the tags, each counted once, and every trace of it', function (): void {
    cachePage('/harbour/', tags: ['content:harbour', 'list:news']);
    cachePage('/ferries/', tags: ['content:ferries', 'list:news']);
    cachePage('/tides/', tags: ['content:tides']);
    $tides = hash('sha256', 'http://localhost/tides/');

    expect(app(PageStore::class)->purge(['list:news', 'content:harbour', 'content:nowhere']))->toBe(2)
        ->and(cacheKept('/harbour/', '/ferries/', '/tides/'))->toBe(['/tides/'])
        ->and(array_keys(cacheValkeyKeys($this->prefix)))->toEqualCanonicalizing(['0:page:'.$tides, '0:tags:'.$tides, '0:tag:content:tides', '0:expiries']);
});

it('flushes by moving to a new generation: nothing stored before is found, and nothing is listed', function (): void {
    cachePage('/harbour/');
    cachePage('/ferries/');

    expect(app(PageStore::class)->flush())->toBe(2)
        ->and(cacheKept('/harbour/', '/ferries/'))->toBe([])
        ->and($this->valkey->get($this->prefix.'generation'))->toBe('1');

    cachePage('/harbour/');

    expect(cacheKept('/harbour/'))->toBe(['/harbour/'])
        ->and(app(PageStore::class)->purge(['*']))->toBe(1)
        ->and(cacheKept('/harbour/'))->toBe([]);
});

it('forgets one page and every trace of it, and leaves the rest', function (): void {
    cachePage('/harbour/');
    cachePage('/ferries/');

    app(PageStore::class)->forget('http://localhost/harbour/');

    expect(cacheKept('/harbour/', '/ferries/'))->toBe(['/ferries/'])
        ->and(cacheValkeyKeys($this->prefix))->not->toHaveKey('0:tags:'.hash('sha256', 'http://localhost/harbour/'))
        ->and($this->valkey->zcard($this->prefix.'0:expiries'))->toBe(1);
});

it('sets a page aside under a list\'s tag by renaming it: stale() finds it, get() no longer does, and the page stored again takes its place', function (): void {
    $hash = hash('sha256', 'http://localhost/front/');
    cachePage('/front/', '<p>The front page as it was</p>', lifetime: 3600, tags: [PageTags::url('/front/'), 'list:post']);

    expect(app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300))->toBe(1)
        ->and(cacheEntry('/front/'))->toBeNull()
        ->and(cacheSetAside('/front/')?->body)->toBe('<p>The front page as it was</p>')
        ->and(cacheValkeyKeys($this->prefix))->toHaveKey('0:stale:'.$hash)->not->toHaveKey('0:page:'.$hash)
        ->and($this->valkey->ttl($this->prefix.'0:stale:'.$hash))->toBeLessThanOrEqual(300);

    cachePage('/front/', '<p>The front page now</p>', tags: [PageTags::url('/front/'), 'list:post']);

    expect(cacheEntry('/front/')?->body)->toBe('<p>The front page now</p>')
        ->and(cacheSetAside('/front/'))->toBeNull();
});

it('sets aside each page carrying any of the tags, once', function (): void {
    cachePage('/one/', tags: [PageTags::url('/one/'), 'list:post', 'author:7']);
    cachePage('/two/', tags: [PageTags::url('/two/'), 'author:7']);
    cachePage('/three/', tags: [PageTags::url('/three/'), 'list:page']);

    expect(app(PageStore::class)->markStale(['list:post', 'author:7'], now()->getTimestamp() + 300))->toBe(2)
        ->and(cacheKept('/one/', '/two/', '/three/'))->toBe(['/three/']);
});

it('removes a set-aside copy with its page: by a purge of any of its tags, forget() and flush()', function (): void {
    foreach (['/one/', '/two/', '/three/'] as $uri) {
        cachePage($uri, tags: [PageTags::url($uri), 'list:post', 'content:'.trim($uri, '/')]);
    }
    app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300);

    app(PageStore::class)->purge(['content:one']);
    app(PageStore::class)->forget('http://localhost/two/');

    expect(cacheSetAside('/one/'))->toBeNull()
        ->and(cacheSetAside('/two/'))->toBeNull()
        ->and(cacheSetAside('/three/'))->not->toBeNull();

    app(PageStore::class)->flush();

    expect(cacheSetAside('/three/'))->toBeNull();
});

it('keeps a set-aside copy until the moment it was given, and prunes it then with every trace of it', function (): void {
    cachePage('/front/', lifetime: 3600, tags: [PageTags::url('/front/'), 'list:post']);
    app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300);

    app(PageStore::class)->prune(now()->getTimestamp() + 299);

    expect(cacheSetAside('/front/'))->not->toBeNull();

    app(PageStore::class)->prune(now()->getTimestamp() + 300);

    expect(cacheSetAside('/front/'))->toBeNull()
        ->and(cacheValkeyKeys($this->prefix))->toBe([]);
});

it('prunes only expired pages, and leaves a page stored again with a later life', function (): void {
    cachePage('/old/', lifetime: 60);
    cachePage('/fresh/', lifetime: 600);
    cachePage('/again/', lifetime: 60);
    $this->travel(61)->seconds();
    cachePage('/again/', lifetime: 60);

    expect(app(PageStore::class)->prune(now()->getTimestamp()))->toBe(1)
        ->and(cacheKept('/old/', '/fresh/', '/again/'))->toBe(['/fresh/', '/again/']);
});

it('walks a tag carried by more pages than one script takes, a batch at a time', function (): void {
    foreach (range(1, 1001) as $n) {
        cachePage("/p{$n}/", tags: [PageTags::url("/p{$n}/"), 'settings']);
    }

    expect(app(PageStore::class)->markStale(['settings'], now()->getTimestamp() + 300))->toBe(1001)
        ->and(app(PageStore::class)->purge(['settings']))->toBe(1001)
        ->and(cacheValkeyKeys($this->prefix))->toBe([]);
});

it('gives every key but the generation a life, so a flushed generation empties itself', function (): void {
    cachePage('/front/', tags: [PageTags::url('/front/'), 'list:post']);
    cachePage('/harbour/');
    app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300);
    app(PageStore::class)->lock(PageKey::of(Request::create('/harbour/')) ?? throw new LogicException('No key.'), PageCache::LOCK_SECONDS)->get();
    app(PageStore::class)->flush();
    cachePage('/ferries/');

    $lives = cacheValkeyKeys($this->prefix);

    expect($lives)->toHaveKeys(['generation', '0:stale:'.hash('sha256', 'http://localhost/front/'), '1:page:'.hash('sha256', 'http://localhost/ferries/')])
        ->and(array_filter(array_diff_key($lives, ['generation' => true]), fn (int $life): bool => $life <= 0))->toBe([]);
});

it('takes a page\'s lock in Valkey, beside its pages, for one request at a time', function (): void {
    $key = PageKey::of(Request::create('/big-story/')) ?? throw new LogicException('No key.');
    $lock = app(PageStore::class)->lock($key, PageCache::LOCK_SECONDS);

    expect($lock->get())->toBeTrue()
        ->and(app(PageStore::class)->lock($key, PageCache::LOCK_SECONDS)->get())->toBeFalse()
        ->and(cacheValkeyKeys($this->prefix))->toHaveKey($key->lockName());

    $lock->release();

    expect(app(PageStore::class)->lock($key, PageCache::LOCK_SECONDS)->get())->toBeTrue();
});

it('finds a page with one command to Valkey', function (): void {
    cachePage('/harbour/');
    $commands = [];
    Redis::enableEvents();
    Redis::purge('page-cache');
    Event::listen(CommandExecuted::class, function (CommandExecuted $executed) use (&$commands): void {
        $commands[] = $executed->command;
    });

    expect(cacheEntry('/harbour/'))->not->toBeNull()
        ->and($commands)->toBe(['eval']);
});

it('finds the addresses of the pages that carry any of these tags and answered 200, fresh or set aside, a tag\'s set never read whole', function (): void {
    cachePage('/harbour/', tags: ['content:ferry']);
    cachePage('/ferries/', tags: ['list:ferries']);
    cachePage('/tides/', tags: ['content:tides']);
    app(PageStore::class)->put(new PageEntry('http://localhost/gone/', 404, [], '<p>Not found</p>', now()->getTimestamp(), now()->getTimestamp() + 10, ['content:ferry']));
    app(PageStore::class)->markStale(['list:ferries'], now()->getTimestamp() + 300);

    expect(app(PageStore::class)->keys(['content:ferry', 'list:ferries'], 10))->toEqualCanonicalizing(['http://localhost/harbour/', 'http://localhost/ferries/'])
        ->and(app(PageStore::class)->keys(['list:ferries', 'content:tides'], 1))->toHaveCount(1)
        ->and(app(PageStore::class)->keys([], 10))->toBe([]);
});
