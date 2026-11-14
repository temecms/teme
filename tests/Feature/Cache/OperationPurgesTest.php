<?php

declare(strict_types=1);

use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PublicChanges;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Teme\Contracts\Cache\CacheKeysFor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folder = cacheOn();
    cacheShown(['/shows-the-ferry/' => ['content:ferry'], '/shows-the-harbour/' => ['content:harbour']]);
    $this->ferry = new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry']);
    $this->harbour = new CacheKeysFor(CacheKeysFor::CONTENT, 'harbour', ['content:harbour']);
});

afterEach(fn () => cacheOff($this->folder));

it('purges what perform() told it once the Operation commits, and nothing else', function (): void {
    cacheProbe($this->ferry)->execute(schedProbeInput());

    expect(cacheKept('/shows-the-ferry/', '/shows-the-harbour/'))->toBe(['/shows-the-harbour/']);
});

it('waits for the outermost commit when the Operation runs inside another transaction', function (): void {
    DB::transaction(function (): void {
        cacheProbe($this->ferry)->execute(schedProbeInput());

        expect(cacheKept('/shows-the-ferry/'))->toBe(['/shows-the-ferry/']);
    });

    expect(cacheKept('/shows-the-ferry/'))->toBe([]);
});

it('purges nothing when the outer transaction rolls back (rule 6)', function (): void {
    cacheRolledBack(fn () => cacheProbe($this->ferry)->execute(schedProbeInput()));

    expect(cacheKept('/shows-the-ferry/'))->toBe(['/shows-the-ferry/']);
});

it('purges nothing when perform() fails', function (): void {
    expect(fn () => cacheProbe($this->ferry, fails: true)->execute(schedProbeInput()))->toThrow(RuntimeException::class, 'on purpose');

    expect(cacheKept('/shows-the-ferry/'))->toBe(['/shows-the-ferry/']);
});

it('purges what an Operation run inside another told it, once the outer one commits', function (): void {
    cacheProbe($this->harbour, inner: cacheProbe($this->ferry))->execute(schedProbeInput());

    expect(cacheKept('/shows-the-ferry/', '/shows-the-harbour/'))->toBe([]);
});

it('purges neither when the outer Operation fails after an inner one succeeded', function (): void {
    expect(fn () => cacheProbe($this->harbour, fails: true, inner: cacheProbe($this->ferry))->execute(schedProbeInput()))->toThrow(RuntimeException::class);

    expect(cacheKept('/shows-the-ferry/', '/shows-the-harbour/'))->toHaveCount(2);
});

it('purges nothing while the page cache is off', function (): void {
    config(['page_cache.enabled' => false]);

    cacheProbe($this->ferry)->execute(schedProbeInput());

    expect(cacheKept('/shows-the-ferry/'))->toBe(['/shows-the-ferry/']);
});

it('never lets a purge that fails break the Operation that asked for it', function (): void {
    Exceptions::fake();
    $this->app->bind(PageStore::class, fn (): PageStore => new class implements PageStore
    {
        public function get(PageKey $key): ?PageEntry
        {
            return null;
        }

        public function put(PageEntry $entry): void {}

        public function stale(PageKey $key): ?PageEntry
        {
            return null;
        }

        public function markStale(array $tags, int $until): int
        {
            return 0;
        }

        public function purge(array $tags): int
        {
            throw new RuntimeException('The page cache folder is gone.');
        }

        public function forget(string $key): void {}

        public function flush(): int
        {
            return 0;
        }

        public function prune(int $now): int
        {
            return 0;
        }

        public function lock(PageKey $key, int $seconds): Lock
        {
            return new NoLock($key->lockName(), $seconds);
        }

        public function keys(array $tags, int $limit): array
        {
            return [];
        }
    });

    expect(cacheProbe($this->ferry)->execute(schedProbeInput()))->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

it('refuses to be told of a change outside an Operation (rule 1)', function (): void {
    expect(fn () => app(PublicChanges::class)->add($this->ferry))->toThrow(LogicException::class, 'rule 1');
});

it('purges every page that read the site\'s settings when an administrator changes them', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = (string) User::factory()->create()->assignRole('administrator')->id;
    cacheShown(['/reads-settings/' => ['settings'], '/reads-the-design/' => ['theme']]);
    $rename = fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'The Harbour Times'], [], [], (int) DB::table('settings')->where('group_key', 'general')->value('version'), $admin));

    cacheRolledBack($rename);

    expect(cacheKept('/reads-settings/', '/reads-the-design/'))->toHaveCount(2);

    $rename();

    expect(cacheKept('/reads-settings/', '/reads-the-design/'))->toBe(['/reads-the-design/']);
});

it('purges nothing for settings no page reads, or for a save that changes nothing', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = (string) User::factory()->create()->assignRole('administrator')->id;
    cacheShown(['/reads-settings/' => ['settings']]);

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('content', ['trash_days' => 45], [], [], 0, $admin));
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'My Teme site'], [], [], 0, $admin));

    expect(cacheKept('/reads-settings/'))->toBe(['/reads-settings/']);
});
