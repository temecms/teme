<?php

declare(strict_types=1);

use App\Domain\Api\ApiPaths;
use App\Domain\Api\ApiResponse;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Http\Api\Middleware\EnsureApiOn;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    Switches::apiOn();
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('_probe', fn () => ApiResponse::one(['ok' => true], ['self' => url('/api/v1/_probe')]));
});

/**
 * The ETag a body is given: a strong one, the first 32 hex characters of
 * its SHA-256, quoted.
 */
function shellEtag(string $body): string
{
    return '"'.substr(hash('sha256', $body), 0, 32).'"';
}

it('answers JSON in one envelope, with no session and no cookie', function (): void {
    $response = $this->get('/api/v1/_probe');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeaderMissing('Set-Cookie');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and($response->json('data'))->toBe(['ok' => true])
        ->and($response->headers->getCookies())->toBe([])
        ->and(session()->isStarted())->toBeFalse();
});

it('marks every answer nosniff and noindex', function (string $path): void {
    // Outside production every answer is noindex, nofollow (I-291).
    $this->app['env'] = 'production';

    $this->get($path)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex');
})->with(['a 200' => '/api/v1/_probe', 'a 404' => '/api/v1/nothing']);

it('gives every 200 a strong ETag of its body', function (): void {
    $response = $this->get('/api/v1/_probe')->assertOk();

    expect($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{32}"$/')
        ->toBe(shellEtag((string) $response->getContent()))
        ->and($this->get('/api/v1/nothing')->headers->get('ETag'))->toBeNull();
});

it('answers 304 with no body to a matching If-None-Match, drawn or served from the page cache', function (): void {
    $etag = (string) $this->get('/api/v1/_probe')->headers->get('ETag');

    $drawn = $this->get('/api/v1/_probe', ['If-None-Match' => $etag]);

    expect($drawn->getStatusCode())->toBe(304)
        ->and((string) $drawn->getContent())->toBe('');

    $folder = cacheOn();
    $body = '{"data":{"stored":true},"links":{"self":"http://localhost/api/v1/_probe"}}';
    $key = PageKey::of(Request::create('/api/v1/_probe')) ?? throw new LogicException('No key for the probe.');
    $now = now()->getTimestamp();
    app(PageStore::class)->put(new PageEntry($key->url, 200, ['Content-Type' => ['application/json'], 'ETag' => [shellEtag($body)]], $body, $now, $now + 60, [$key->urlTag()]));

    $stored = cacheGet('/api/v1/_probe');
    $served = $this->get('/api/v1/_probe', ['If-None-Match' => shellEtag($body)]);

    expect($stored->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and((string) $stored->getContent())->toBe($body)
        ->and($served->getStatusCode())->toBe(304)
        ->and($served->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and((string) $served->getContent())->toBe('');

    cacheOff($folder);
});

it('answers 200 to an If-None-Match that doesn\'t match', function (): void {
    $this->get('/api/v1/_probe', ['If-None-Match' => '"0123456789abcdef0123456789abcdef"'])
        ->assertOk()
        ->assertJsonPath('data.ok', true);
});

it('answers anything else under /api with a not_found problem', function (string $path): void {
    $this->get($path)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => "The API has nothing at {$path}. Start at http://localhost/api/v1.",
            'instance' => $path,
            'code' => 'not_found',
        ]);
})->with(['/api', '/api/v2/items', '/api/v1/nothing/here']);

it('answers an encoded or upper-case API address with the API\'s own not_found problem', function (string $path): void {
    $response = $this->get($path);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('code'))->toBe('not_found')
        ->and((string) $response->getContent())->not->toContain('<html');
})->with(['/%61pi/v1/x', '/api/%76%31/x', '/%61%70%69', '/api%2Fv1%2Fx', '/API/v1/x', '/Api']);

it('never hands an /api address to the public site', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = ctAdmin();
    $page = addrPublished($admin, 'Harbour opening times', 'page');
    DB::table('paths')->where('target_type', 'content')->where('target_id', $page->id)->where('is_current', true)->update(['path' => 'api/v1/items']);

    $nothing = $this->get('/api/v1/nothing');
    $taken = $this->get('/api/v1/items');

    expect($nothing->getStatusCode())->toBe(404)
        ->and($nothing->json('code'))->toBe('not_found')
        ->and((string) $taken->headers->get('Content-Type'))->toStartWith('application/')
        // The page may be listed as data by the items list; never drawn as the site's page.
        ->and((string) $taken->getContent())->not->toContain('<html')->not->toContain('<title>Harbour opening times');
});

it('answers a POST with method_not_allowed and Allow: GET, HEAD', function (string $path): void {
    $this->post($path)
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'method_not_allowed')
        ->assertJsonPath('detail', 'The API only reads. Use GET.');
})->with(['/api/v1/_probe', '/api/v1/nothing']);

it('answers a 121st drawn request in a minute with rate_limited and Retry-After', function (): void {
    $key = md5('api'.'api:127.0.0.1');

    for ($hit = 0; $hit < ApiPaths::PER_MINUTE; $hit++) {
        RateLimiter::hit($key, 60);
    }

    $response = $this->get('/api/v1/_probe');
    $seconds = (int) $response->headers->get('Retry-After');

    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-RateLimit-Limit', '120')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonPath('code', 'rate_limited')
        ->assertJsonPath('detail', "Too many requests from your address. Try again in {$seconds} seconds.");

    expect($seconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('answers CORS from any origin with no credentials and no Vary', function (): void {
    $answer = $this->get('/api/v1/_probe', ['Origin' => 'https://elsewhere.example']);
    $preflight = $this->call('OPTIONS', '/api/v1/_probe', server: ['HTTP_ORIGIN' => 'https://elsewhere.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);

    expect($answer->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($answer->headers->get('Access-Control-Expose-Headers'))->toBe('ETag, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining')
        ->and($answer->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        ->and(strtolower(implode(',', $answer->headers->all('Vary'))))->not->toContain('origin')
        ->and($this->get('/api/v1/_probe')->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($preflight->getStatusCode())->toBe(204)
        ->and($preflight->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($preflight->headers->get('Access-Control-Allow-Methods'))->toBe('GET, HEAD, OPTIONS')
        ->and(strtolower((string) $preflight->headers->get('Access-Control-Allow-Headers')))->toBe('accept, if-none-match')
        ->and($preflight->headers->get('Access-Control-Max-Age'))->toBe('86400')
        ->and($preflight->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        ->and(strtolower(implode(',', $preflight->headers->all('Vary'))))->not->toContain('origin');
});

it('tries the catch-all last, so a route added after it is still reached, and an address no route takes is still the API\'s problem', function (): void {
    // Registered after routes/api.php's catch-all, as a part's routes added later would be.
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('later/{thing}', fn (string $thing) => ApiResponse::one(['thing' => $thing], ['self' => url("/api/v1/later/{$thing}")]));

    $missing = $this->get('/api/v1/later-not');

    expect($this->get('/api/v1/later/here')->assertOk()->json('data'))->toBe(['thing' => 'here'])
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($missing->json('code'))->toBe('not_found');
});
