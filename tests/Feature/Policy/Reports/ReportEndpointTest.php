<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Site\Policy\PolicyReports;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Policy\Reports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folder = cacheOn();
    config(['app.url' => 'https://ledger.example', 'teme.public_csp' => 'report-only']);
    $this->spool = app(PolicyReportSpool::class);
    // The spool's lines so far.
    $this->lines = function (): array {
        $file = $this->spool->folder().'/'.PolicyReportSpool::OPEN;

        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    };
    // A browser's post, from this address.
    $this->send = fn (string $body, string $type = 'application/csp-report', string $ip = '203.0.113.7') => $this->call(
        'POST', '/_teme/csp', [], [], [], ['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'ledger.example', 'HTTPS' => 'on'], $body,
    );
});

afterEach(fn () => cacheOff($this->folder));

it('answers 204 to a report and spools it', function (): void {
    $response = ($this->send)(Reports::level2());

    $response->assertNoContent();

    expect(($this->lines)())->toHaveCount(1)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([])
        ->and(explode("\t", ($this->lines)()[0])[0])->toBe('img-src');
});

it('lives at the address the policy names', function (): void {
    expect(route('teme.csp'))->toBe(PolicyReports::url())
        ->and(parse_url(PolicyReports::url(), PHP_URL_PATH))->toBe(PolicyReports::PATH);
});

it('answers 204 and spools nothing when the policy is off', function (): void {
    config(['teme.public_csp' => 'off']);

    ($this->send)(Reports::level2())->assertNoContent();

    expect(($this->lines)())->toBe([]);
});

it('answers 204 and spools nothing for a body over 16 KB, by its length or by what was read', function (): void {
    // A whole report followed by spaces: cut at the limit it would still read, so only the size refuses it.
    $long = Reports::level2().str_repeat(' ', 17_000);

    expect(strlen($long))->toBeGreaterThan(16_384);

    ($this->send)($long)->assertNoContent();
    // A length the request announces too small for what follows: the read is bounded too.
    $request = Request::create('/_teme/csp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '203.0.113.8', 'CONTENT_LENGTH' => '100'], $long);
    $response = app(Kernel::class)->handle($request);

    expect($response->getStatusCode())->toBe(204)
        ->and(($this->lines)())->toBe([]);
});

it('answers 204 to anything it can’t read', function (): void {
    foreach ([['not json', 'text/plain'], ['{broken', 'application/csp-report'], ['', 'application/csp-report'], [Reports::level2(), 'text/html']] as [$body, $type]) {
        ($this->send)($body, $type)->assertNoContent();
    }

    expect(($this->lines)())->toBe([]);
});

it('takes ten reports a minute from one address, answering 204 past them and spooling nothing more', function (): void {
    foreach (range(1, 12) as $n) {
        ($this->send)(Reports::level2())->assertNoContent();
    }

    expect(($this->lines)())->toHaveCount(10);
});

it('takes six hundred a minute from the whole site', function (): void {
    foreach (range(1, 60) as $address) {
        foreach (range(1, 10) as $n) {
            ($this->send)(Reports::level2(), ip: "198.51.100.{$address}")->assertNoContent();
        }
    }

    expect(($this->lines)())->toHaveCount(600);

    ($this->send)(Reports::level2(), ip: '198.51.100.200')->assertNoContent();

    expect(($this->lines)())->toHaveCount(600);
});

it('starts no session and writes nothing to the security log or any table', function (string $store): void {
    config(['cache.default' => $store]);
    // The limiter made again on that store, with the limits the application registered.
    $limiter = new RateLimiter(Cache::store($store));
    $limiter->for('csp-reports', app(RateLimiter::class)->limiter('csp-reports'));
    app()->instance(RateLimiter::class, $limiter);
    $tables = [];
    DB::listen(function (QueryExecuted $query) use (&$tables): void {
        if (preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+"?([a-z_]+)"?/i', $query->sql, $match) === 1) {
            $tables[] = $match[1];
        }
    });
    $events = DB::table('security_events')->count();

    $response = ($this->send)(Reports::level2());
    $response->assertNoContent();

    // The tests' array store writes no table at all; production's database store writes only its cache table, as unlock and search do.
    $written = array_values(array_unique($tables));

    expect($written)->toBe($store === 'array' ? [] : ['cache'])
        ->and(DB::table('security_events')->count())->toBe($events)
        ->and($response->headers->getCookies())->toBe([])
        ->and(($this->lines)())->toHaveCount(1);
})->with(['array', 'database']);

it('is passed by the page cache', function (): void {
    $response = ($this->send)(Reports::level2());

    expect($response->headers->has('X-Teme-Cache'))->toBeFalse();

    expect(PageCache::bypass(Request::create('/_teme/csp', 'POST')))->toBe('method')
        ->and(PageCache::bypass(Request::create('https://ledger.example/_teme/csp', 'GET')))->toBe('reserved');
});

it('answers an accepted, a dropped and a limited report identically, headers included', function (): void {
    $headers = function ($response): array {
        $all = array_map(fn (array $values): string => implode(', ', $values), $response->headers->allPreserveCase());
        unset($all['Date']);
        ksort($all);

        return $all;
    };

    $accepted = ($this->send)(Reports::level2(), ip: '203.0.113.50');
    $dropped = ($this->send)('not json', 'text/plain', '203.0.113.51');

    foreach (range(1, 10) as $n) {
        ($this->send)(Reports::level2(), ip: '203.0.113.52');
    }

    $limited = ($this->send)(Reports::level2(), ip: '203.0.113.52');

    foreach ([$accepted, $dropped, $limited] as $response) {
        $response->assertNoContent();

        expect($response->getContent())->toBe('')
            ->and($response->headers->has('X-RateLimit-Limit'))->toBeFalse()
            ->and($response->headers->has('X-RateLimit-Remaining'))->toBeFalse()
            ->and($response->headers->has('Retry-After'))->toBeFalse();
    }

    expect($headers($accepted))->not->toBe([])
        ->and($headers($dropped))->toBe($headers($accepted))
        ->and($headers($limited))->toBe($headers($accepted));
});

it('does not decode a 1 MB JSON body: the size cap is the first thing that reads it', function (string $type): void {
    $big = json_encode(['padding' => str_repeat('a', 1_048_576)]);
    $request = Request::create('/_teme/csp', 'POST', [], [], [], ['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => '203.0.113.60', 'HTTP_HOST' => 'ledger.example', 'HTTPS' => 'on'], $big);

    $response = app(Kernel::class)->handle($request);
    $decoded = (new ReflectionProperty($request, 'json'))->getValue($request);

    expect($response->getStatusCode())->toBe(204)
        ->and($decoded)->toBeNull()
        ->and(($this->lines)())->toBe([]);
})->with(['application/json', 'application/reports+json']);
