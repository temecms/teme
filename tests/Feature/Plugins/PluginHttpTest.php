<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginHttp;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\RequestFailed;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** A door to api.example.com alone, at one public address. */
function apiDoor(): PluginHttp
{
    return new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));
}

it('sends a request to a host the plugin was granted, and gives back the answer', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response(['ok' => true], 201)]);
    $door = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

    $answer = $door->postJson('https://api.example.com/sync', ['id' => 'x']);

    expect($answer->status)->toBe(201)->and($answer->json())->toBe(['ok' => true]);
    Http::assertSent(fn (Request $sent): bool => $sent->url() === 'https://api.example.com/sync' && $sent['id'] === 'x');
});

it('refuses an address it may not reach, sending nothing', function (string $url, string $words): void {
    Http::fake();
    $door = new PluginHttp(['api.example.com', '*.cdn.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14'], 'a.cdn.example.com' => ['93.184.215.15']]));

    expect(fn () => $door->get($url))->toThrow(HostRefused::class, $words);
    Http::assertNothingSent();
})->with([
    'another host' => ['https://evil.example.net/', 'It may send requests only to the hosts you agreed to: api.example.com, *.cdn.example.com.'],
    'the wildcard’s own host' => ['https://cdn.example.com/', 'It may send requests only to the hosts you agreed to'],
    'plain http' => ['http://api.example.com/', 'Teme sends requests only over https.'],
    'another port' => ['https://api.example.com:8443/', 'Teme sends requests only to port 443.'],
    'a name and password' => ['https://me:pw@api.example.com/', 'An address may not carry a name or password.'],
    'an address for a host' => ['https://93.184.215.14/', 'It may send requests only to the hosts you agreed to'],
]);

it('reaches a host below a wildcard it was granted', function (): void {
    Http::fake(['https://a.cdn.example.com/*' => Http::response('fine')]);
    $door = new PluginHttp(['*.cdn.example.com'], FakeResolver::answering(['a.cdn.example.com' => ['93.184.215.15']]));

    expect($door->get('https://a.cdn.example.com/file')->body)->toBe('fine');
});

it('refuses a host that resolves to a private address, even once among public ones', function (array $addresses): void {
    Http::fake();
    $door = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => $addresses]));

    expect(fn () => $door->get('https://api.example.com/'))->toThrow(HostRefused::class, 'api.example.com points to a private address, so Teme won’t send to it.');
    Http::assertNothingSent();
})->with([[['10.0.0.5']], [['127.0.0.1']], [['93.184.215.14', '192.168.1.2']], [['::1']], [['::ffff:127.0.0.1']], [['100.64.0.1']], [['fe80::1']], [['169.254.169.254']]]);

it('refuses a host with no address, saying nothing of what it looked up', function (): void {
    Http::fake();
    $door = new PluginHttp(['api.example.com'], FakeResolver::answering([]));

    expect(fn () => $door->get('https://api.example.com/'))->toThrow(HostRefused::class, 'api.example.com has no address.');
    Http::assertNothingSent();
});

it('follows no redirect: the plugin is given the 3xx', function (): void {
    Http::fake([
        'https://api.example.com/old' => Http::response('', 301, ['Location' => 'https://evil.example.net/']),
        '*' => Http::response('followed', 200),
    ]);

    $answer = apiDoor()->get('https://api.example.com/old');

    expect($answer->status)->toBe(301)
        ->and($answer->headers['Location'] ?? null)->toBe(['https://evil.example.net/']);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'evil.example.net'));
});

it('cuts an answer over 1 MB, and never waits more than 10 seconds', function (): void {
    $sent = [];
    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = $options;

        return Http::response(str_contains($request->url(), 'large') ? str_repeat('a', PluginHttp::MOST_BYTES + 1) : 'small');
    });

    expect(fn () => apiDoor()->get('https://api.example.com/large'))->toThrow(AnswerTooLarge::class, 'The answer from api.example.com was larger than 1 MB.')
        ->and(apiDoor()->get('https://api.example.com/small', [], 60)->body)->toBe('small')
        ->and(apiDoor()->get('https://api.example.com/small', [], 3)->body)->toBe('small')
        ->and(array_column($sent, 'timeout'))->toBe([5, 10, 3])
        ->and(array_column($sent, 'connect_timeout'))->toBe([5, 5, 3])
        ->and(array_column($sent, 'allow_redirects'))->toBe([false, false, false]);
});

it('drops a Host header the plugin tries to set', function (): void {
    Http::fake();

    apiDoor()->get('https://api.example.com/', ['host' => 'evil.example.net', 'Host' => 'evil.example.net', 'X-Key' => 'k']);

    Http::assertSent(fn (Request $sent): bool => $sent->header('Host') === ['api.example.com'] && $sent->header('X-Key') === ['k']);
});

it('connects only to the address it checked, over https, with no proxy and no answer past 1 MB', function (): void {
    $options = PluginHttp::transport('api.example.com', '2606:2800:220:1::248');

    // Only the pin goes to curl itself; Guzzle refuses the rest there, and sets them from its own options.
    expect($options['curl'])->toBe([CURLOPT_RESOLVE => ['api.example.com:443:[2606:2800:220:1::248]']])
        ->and($options['protocols'])->toBe(['https'])
        ->and($options['proxy'])->toBe(['no' => ['*']])
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options)->not->toHaveKey('stream')
        ->and(PluginHttp::transport('api.example.com', '93.184.215.14')['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.215.14'])
        ->and($options['progress'](0, PluginHttp::MOST_BYTES, 0, 0))->toBeFalse();

    expect(fn () => $options['progress'](0, PluginHttp::MOST_BYTES + 1, 0, 0))
        ->toThrow(AnswerTooLarge::class, 'The answer from api.example.com was larger than 1 MB.');
    expect(fn () => $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1048577'])))
        ->toThrow(AnswerTooLarge::class, 'The answer from api.example.com was larger than 1 MB.');
    $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1048576']));
});

it('takes the host Guzzle would connect to, not one a lax parser sees', function (string $url): void {
    Http::fake();

    expect(fn () => apiDoor()->get($url))->toThrow(HostRefused::class);
    Http::assertNothingSent();
})->with(['https://api.example.com\@evil.example.net/', 'https://evil.example.net#@api.example.com/']);

it('refuses a body over 1 MB before looking the host up or sending anything', function (): void {
    Http::fake();
    $unasked = new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            throw new RuntimeException('The door looked the host up.');
        }
    };
    $door = new PluginHttp(['api.example.com'], $unasked);
    $json = ['blob' => str_repeat('x', PluginHttp::MOST_BYTES)];

    expect(fn () => $door->postJson('https://api.example.com/sync', $json))
        ->toThrow(RequestFailed::class, 'The request to api.example.com was larger than 1 MB, so Teme didn’t send it.');
    Http::assertNothingSent();
});

it('sends a body of exactly 1 MB, as JSON', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response('', 204)]);
    $json = ['blob' => str_repeat('x', PluginHttp::MOST_BYTES - strlen('{"blob":""}'))];

    expect(apiDoor()->postJson('https://api.example.com/sync', $json)->status)->toBe(204);
    Http::assertSent(fn (Request $sent): bool => strlen($sent->body()) === PluginHttp::MOST_BYTES
        && $sent->hasHeader('Content-Type', 'application/json')
        && $sent->data() === $json);
});
