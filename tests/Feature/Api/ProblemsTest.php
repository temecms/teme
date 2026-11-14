<?php

declare(strict_types=1);

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Api\InvalidParameters;
use App\Http\Api\Middleware\EnsureApiOn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    Switches::apiOn();
});

/**
 * A probe under /api/v1 that throws what it is given.
 */
function problemsProbe(Throwable $thrown): void
{
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('_probe', fn () => throw $thrown);
}

it('writes every problem with type, title, status, detail, instance and code', function (Closure $thrown, int $status, string $title, string $code, string $detail, array $headers): void {
    problemsProbe($thrown());

    $response = $this->get('/api/v1/_probe');
    $body = (array) $response->json();
    $expected = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'instance' => '/api/v1/_probe', 'code' => $code];

    if ($code === 'invalid_parameter') {
        $expected['errors'] = [
            ['parameter' => 'colour', 'detail' => 'colour isn’t a parameter this address takes.'],
            ['parameter' => 'limit', 'detail' => 'limit must be a whole number from 1 to 100.'],
        ];
    }

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('ETag'))->toBeNull()
        ->and($body)->toBe($expected);

    foreach ($headers as $name => $value) {
        expect($response->headers->get($name))->toBe($value);
    }
})->with([
    'invalid_parameter' => [fn () => new InvalidParameters(['colour' => 'colour isn’t a parameter this address takes.', 'limit' => 'limit must be a whole number from 1 to 100.']), 400, 'Bad Request', 'invalid_parameter', 'One or more query parameters can’t be used.', []],
    'not_found, an item' => [fn () => ApiNotFound::item('01jcb7x0q8m2v3k4n5p6r7s8t9'), 404, 'Not Found', 'not_found', 'No published item has the ID 01jcb7x0q8m2v3k4n5p6r7s8t9.', []],
    'not_found, by path' => [fn () => ApiNotFound::path('/movies/arrival/'), 404, 'Not Found', 'not_found', 'No published item has the address /movies/arrival/.', []],
    'not_found, a term' => [fn () => ApiNotFound::term('01jcaa0b1c2d3e4f5g6h7j8k9m'), 404, 'Not Found', 'not_found', 'No public term has the ID 01jcaa0b1c2d3e4f5g6h7j8k9m.', []],
    'not_found, a file' => [fn () => ApiNotFound::file('01JCB8Y4ZK2Q9W3E5R7T1V3X5P'), 404, 'Not Found', 'not_found', 'No published item shows a file with the ID 01JCB8Y4ZK2Q9W3E5R7T1V3X5P.', []],
    'api_off' => [fn () => ApiNotFound::off(), 404, 'Not Found', 'api_off', 'This site doesn’t share its content through the API.', []],
    'rate_limited' => [fn () => new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => '17', 'X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '0']), 429, 'Too Many Requests', 'rate_limited', 'Too many requests from your address. Try again in 17 seconds.', ['Retry-After' => '17', 'X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '0']],
    'method_not_allowed' => [fn () => new MethodNotAllowedHttpException(['GET', 'HEAD']), 405, 'Method Not Allowed', 'method_not_allowed', 'The API only reads. Use GET.', ['Allow' => 'GET, HEAD']],
    'not_found, a route\'s own' => [fn () => new NotFoundHttpException('/var/www/app/Secret.php'), 404, 'Not Found', 'not_found', 'The API has nothing at /api/v1/_probe. Start at http://localhost/api/v1.', []],
    'not_found, a model' => [fn () => (new ModelNotFoundException)->setModel('App\Models\User', [7]), 404, 'Not Found', 'not_found', 'The API has nothing at /api/v1/_probe. Start at http://localhost/api/v1.', []],
    'unavailable' => [fn () => new HttpException(503, 'Down for SELECT', null, ['Retry-After' => '60']), 503, 'Service Unavailable', 'unavailable', 'The site is being updated. Try again shortly.', ['Retry-After' => '60']],
    'http_error' => [fn () => new HttpException(418, 'Teapot at /srv/app.php'), 418, 'I\'m a teapot', 'http_error', 'The request couldn’t be answered.', []],
    'server_error' => [fn () => new RuntimeException('boom'), 500, 'Internal Server Error', 'server_error', 'Something went wrong on the site. It has been noted.', []],
]);

it('answers a server error with no message, class or path, in debug mode too', function (): void {
    config(['app.debug' => true]);
    problemsProbe(new RuntimeException('SELECT secret FROM users'));

    $response = $this->get('/api/v1/_probe');
    $body = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('code'))->toBe('server_error')
        ->and($body)->not->toContain('SELECT')
        ->not->toContain('RuntimeException')
        ->not->toContain('app/')
        ->not->toContain('.php');
});

it('still reports a server error', function (): void {
    Exceptions::fake();
    problemsProbe(new RuntimeException('SELECT secret FROM users'));

    $this->get('/api/v1/_probe')->assertStatus(500);

    Exceptions::assertReported(RuntimeException::class);
});

it('reports nothing for an address it doesn\'t have, an API that is off or a bad parameter', function (string $case): void {
    Exceptions::fake();
    problemsProbe(new InvalidParameters(['tpye' => 'tpye isn’t a parameter here.']));

    if ($case === 'the API off') {
        Switches::apiOff();
    }

    $path = match ($case) {
        'an unknown address' => '/api/x',
        default => '/api/v1/_probe',
    };
    $expected = match ($case) {
        'an unknown address' => 404,
        'the API off' => 404,
        default => 400,
    };

    expect($this->get($path)->getStatusCode())->toBe($expected);

    Exceptions::assertNothingReported();
})->with(['an unknown address', 'the API off', 'a bad parameter']);

it('answers bad bytes in a parameter with a 400 problem that is valid JSON, and reports nothing', function (string $query): void {
    Exceptions::fake();
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('_query', fn (Request $request) => ApiResponse::one(ApiQuery::from($request, ['type'])->values, []));

    $response = $this->get('/api/v1/_query?'.$query);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code'])->toBe('invalid_parameter');

    Exceptions::assertNothingReported();
})->with(['%FF=1', 'type=%FF', 'ty%00pe=1', 'type=a%00b', str_repeat('n', 5000).'=1']);

it('marks a problem nosniff and noindex when nothing past routing ran', function (string $case): void {
    // Outside production every answer is noindex, nofollow (I-291).
    $this->app['env'] = 'production';

    if ($case === 'maintenance mode') {
        // In memory, so a failing run leaves no file behind to take the next test down.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        app()->forgetInstance(MaintenanceModeManager::class);
        app()->maintenanceMode()->activate(['retry' => 60]);
    }

    $response = $case === 'a POST' ? $this->post('/api/v1/nothing') : $this->get('/api/v1/nothing');

    expect($response->getStatusCode())->toBe($case === 'a POST' ? 405 : 503)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('code'))->toBe($case === 'a POST' ? 'method_not_allowed' : 'unavailable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');

    if ($case === 'maintenance mode') {
        expect($response->headers->get('Retry-After'))->toBe('60');
    }
})->with(['a POST', 'maintenance mode']);
