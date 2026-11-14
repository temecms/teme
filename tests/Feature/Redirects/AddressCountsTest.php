<?php

declare(strict_types=1);

use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\NotFoundLog;
use App\Domain\Redirects\Operations\Input\RecordAddressCountsInput;
use App\Domain\Redirects\Operations\RecordAddressCounts;
use App\Domain\Redirects\Queries\NotFoundEntries;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    // The spool's folder, this test's own: the page cache's, the cache itself left off.
    $this->folder = sys_get_temp_dir().'/teme-counts-'.getmypid().'-'.uniqid();
    config(['app.url' => 'http://localhost', 'page_cache.stores.file.path' => $this->folder]);
    $this->editor = User::factory()->create()->assignRole('editor');
    // A reader's GET of this exact path, from this page if one is given.
    $this->visit = fn (string $uri, ?string $referer = null): TestResponse => TestResponse::fromBaseResponse(
        app(HttpKernel::class)->handle(Request::create($uri, 'GET', server: $referer === null ? [] : ['HTTP_REFERER' => $referer])),
    );
    // The open spool's lines, each without its time.
    $this->spooled = function (): array {
        $file = app(AddressCounts::class)->folder().'/'.AddressCounts::OPEN;
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return array_map(fn (string $line): string => implode("\t", array_slice(explode("\t", $line), 0, -1)), $lines);
    };
    // One minute's run, as the scheduler makes it.
    $this->count = fn (): int => $this->artisan('teme:redirects:count')->assertSuccessful()->run();
});

afterEach(fn () => cacheOff($this->folder));

it('appends one line for each redirect hit and each address not found, and writes nothing to the database for either (I-298)', function (): void {
    $id = rdSaved('/old-fares', '/news/');
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    ($this->visit)('/old-fares/')->assertStatus(301);
    ($this->visit)('/Never-Was/', 'https://www.Example.com/search?q=fares')->assertNotFound();

    foreach (range(1, 20) as $n) {
        ($this->visit)("/flood-{$n}/")->assertNotFound();
    }

    expect(array_slice(($this->spooled)(), 0, 2))->toBe(["h\t{$id}", "n\tnever-was\twww.example.com"])
        ->and(($this->spooled)())->toHaveCount(22)
        ->and($writes)->toBe([]);
});

it('counts a not-found page the page cache serves, as one drawn is, and no other page it serves (I-262)', function (): void {
    $cache = cacheOn();
    addrPublished($this->editor, 'Harbour news');

    ($this->visit)('/never-was/')->assertHeader('X-Teme-Cache', 'MISS');
    ($this->visit)('/never-was/')->assertHeader('X-Teme-Cache', 'HIT');
    ($this->visit)('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');
    ($this->visit)('/harbour-news/')->assertHeader('X-Teme-Cache', 'HIT');

    expect(($this->spooled)())->toBe(["n\tnever-was\t", "n\tnever-was\t"]);

    cacheOff($cache);
});

it('ignores probes and addresses no log can keep, and keeps a referrer\'s host alone (I-298)', function (): void {
    expect(array_map(NotFoundLog::ignored(...), ['wp-login.php', 'xmlrpc.php', 'wp-admin/setup-config.php', '.env', '.git/config', 'app/.env', "old\x01page", str_repeat('a', 2001)]))->toBe(array_fill(0, 8, true))
        ->and(array_map(NotFoundLog::ignored(...), ['news/v1.2', 'wp-content/uploads/2021/06/market.jpg', 'ferry-fares', '']))->toBe([false, false, false, false])
        ->and(array_map(NotFoundLog::referrerHost(...), ['https://www.Google.com/search?q=fares', 'android-app://com.slack/', 'not an address', 'https://exa_mple.com/', null]))->toBe(['www.google.com', 'com.slack', null, null, null]);

    ($this->visit)('/wp-login.php')->assertNotFound();

    // The list is the minute's run's (I-874): a reader's request spools the probe, and the run leaves it out.
    expect(($this->spooled)())->toBe(["n\twp-login.php\t"]);

    ($this->count)();
    ($this->count)();

    expect(DB::table('not_found_log')->count())->toBe(0);
});

it('drops lines once the open spool passes its size, so a stopped scheduler can\'t fill the disk', function (): void {
    $counts = app(AddressCounts::class);
    $open = $counts->folder().'/'.AddressCounts::OPEN;
    mkdir($counts->folder(), 0775, true);
    // A sparse file: its size at once, none of its bytes written.
    $handle = fopen($open, 'c');
    ftruncate($handle, AddressCounts::MOST_BYTES + 1);
    fclose($handle);

    $counts->notFound('/never-was/', null);
    clearstatcache();

    expect(filesize($open))->toBe(AddressCounts::MOST_BYTES + 1);
});

it('writes the counts a run after they were spooled: hits on each redirect, and one row per address with its latest referring host (I-297, I-298)', function (): void {
    $id = rdSaved('/old-fares', '/news/');
    ($this->visit)('/old-fares/');
    ($this->visit)('/old-fares/');
    ($this->visit)('/never-was/', 'https://www.example.com/a');
    ($this->visit)('/Never-Was');
    ($this->visit)('/other/', 'https://t.co/x');

    ($this->count)();

    expect(DB::table('not_found_log')->count())->toBe(0)
        ->and(DB::table('redirects')->where('id', $id)->value('hits'))->toBe(0);

    $this->artisan('teme:redirects:count')->expectsOutputToContain('Redirect hits: 2. “Not found” answers: 3.')->assertSuccessful();

    expect(DB::table('redirects')->where('id', $id)->value('hits'))->toBe(2)
        ->and(CarbonImmutable::parse((string) DB::table('redirects')->where('id', $id)->value('last_hit_at'))->getTimestamp())->toBe(now()->getTimestamp())
        ->and(DB::table('not_found_log')->orderBy('path')->get(['path', 'count', 'referrer_host'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['path' => 'never-was', 'count' => 2, 'referrer_host' => 'www.example.com'],
            ['path' => 'other', 'count' => 1, 'referrer_host' => 't.co'],
        ]);

    $this->artisan('schedule:list')->expectsOutputToContain('teme:redirects:count')->assertSuccessful();
});

it('keeps the log at its cap, the least recently seen making room, however many addresses a flood asks for (I-298)', function (): void {
    $this->app->instance(Elevation::class, new OperatorElevation);
    DB::table('not_found_log')->insert([
        ['path' => 'old-one', 'count' => 9, 'first_seen_at' => now()->subDays(2), 'last_seen_at' => now()->subDay(), 'referrer_host' => null],
        ['path' => 'old-two', 'count' => 9, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(2), 'referrer_host' => null],
    ]);

    foreach (range(1, 5) as $n) {
        $this->travel(1)->seconds();
        ($this->visit)("/flood-{$n}/");
    }

    $record = fn (): array => app(RecordAddressCounts::class)->execute(new RecordAddressCountsInput(keep: 2));
    $record();

    expect($record())->toBe(['hits' => 0, 'notFound' => 5])
        ->and(DB::table('not_found_log')->orderBy('path')->pluck('path')->all())->toBe(['flood-4', 'flood-5']);
});

it('marks a row a redirect now answers as Redirected, exact or a pattern, and it stops counting (I-871)', function (): void {
    ($this->visit)('/old-fares/');
    ($this->visit)('/2019/ferry/');
    ($this->count)();
    ($this->count)();
    $exact = rdSaved('/old-fares', '/news/');
    $pattern = rdSaved('/2019/[slug]', '/[slug]/');

    ($this->visit)('/old-fares/')->assertStatus(301);
    ($this->count)();
    ($this->count)();

    expect(array_map(fn (array $row): array => [$row['address'], $row['count'], $row['redirectId']], app(NotFoundEntries::class)->mostAsked(10)['rows']))->toBe([
        ['/2019/ferry', 1, $pattern],
        ['/old-fares', 1, $exact],
    ]);
});

it('lists the log the most asked for first, then by address, a page at a time', function (): void {
    foreach (['a' => 3, 'b' => 5, 'c' => 3, 'd' => 1] as $path => $count) {
        DB::table('not_found_log')->insert(['path' => $path, 'count' => $count, 'first_seen_at' => now(), 'last_seen_at' => now(), 'referrer_host' => null]);
    }

    $log = app(NotFoundEntries::class);
    $first = $log->mostAsked(2);
    $second = $log->mostAsked(2, $first['next']);

    expect(array_column($first['rows'], 'path'))->toBe(['b', 'a'])
        ->and($first['next'])->toBe(['count' => 3, 'path' => 'a'])
        ->and(array_column($second['rows'], 'path'))->toBe(['c', 'd'])
        ->and($second['next'])->toBeNull();
});

it('writes nothing for anyone but the operator (authorization)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);
    ($this->visit)('/never-was/');

    expect(fn () => app(RecordAddressCounts::class)->execute(new RecordAddressCountsInput((string) $person->id)))->toThrow(AuthorizationException::class)
        ->and(($this->spooled)())->toBe(["n\tnever-was\t"])
        ->and(file_exists(app(AddressCounts::class)->folder().'/'.AddressCounts::CLOSED))->toBeFalse()
        ->and(DB::table('not_found_log')->count())->toBe(0);
})->with(['administrator', 'editor']);

it('counts every line once, one a reader wrote to the spool just as it closed included', function (): void {
    ($this->visit)('/a/');
    ($this->count)();
    // A request that opened the file before the run renamed it, and wrote after.
    file_put_contents(app(AddressCounts::class)->folder().'/'.AddressCounts::CLOSED, "n\tb\t\t".now()->getTimestamp()."\n", FILE_APPEND);
    ($this->visit)('/c/');
    ($this->count)();
    ($this->count)();
    ($this->count)();

    expect(DB::table('not_found_log')->orderBy('path')->pluck('count', 'path')->all())->toBe(['a' => 1, 'b' => 1, 'c' => 1]);
});
