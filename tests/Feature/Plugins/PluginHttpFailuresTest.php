<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\PluginHttp;
use GuzzleHttp\Exception\InvalidArgumentException as GuzzleInvalidArgument;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\RequestFailed;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\Lists\TlsServer;

/*
 * How the outbound door words a request that got no answer. Each failure is
 * made by the real curl handler on this machine (nothing leaves it), then
 * handed to the door through Http::fake(), the way Guzzle hands it to
 * Laravel's client, so the door's own catch sees what it would in use.
 */
beforeEach(function (): void {
    Http::allowStrayRequests();
});

/** Sends a real GET carrying a secret header and returns what Guzzle failed with. */
function realFailure(string $url, array $options, int $seconds = 2): Throwable
{
    try {
        Http::setHandler(new CurlHandler)->withHeaders(['Authorization' => 'Bearer s3cret-header'])->withOptions($options)->timeout($seconds)->connectTimeout(1)->get($url);
    } catch (ConnectionException $failure) {
        return $failure->getPrevious() ?? $failure;
    }

    throw new RuntimeException('The request didn’t fail.');
}

/**
 * The door's options with api.example.com pinned to 127.0.0.1 on $port.
 *
 * @param  array<string, mixed>  $more
 *
 * @return array<string, mixed>
 */
function pinnedToLocal(int $port, array $more = []): array
{
    return [...PluginHttp::transport('api.example.com', '127.0.0.1'), 'curl' => [CURLOPT_RESOLVE => ["api.example.com:{$port}:127.0.0.1"]], ...$more];
}

/** What the door throws when its request fails with $failure. */
function doorFailingWith(Throwable $failure, string $path = '/v1/secret?token=abc'): Throwable
{
    Http::fake(['https://api.example.com/*' => fn () => $failure instanceof TransferException ? Create::rejectionFor($failure) : throw $failure]);
    $door = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

    try {
        $door->get("https://api.example.com{$path}", ['Authorization' => 'Bearer s3cret-header']);
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new RuntimeException('The door didn’t throw.');
}

dataset('failures', [
    'refused' => [
        fn (): Throwable => realFailure('https://api.example.com/v1/secret?token=abc', PluginHttp::transport('api.example.com', '127.0.0.1')),
        'The connection to api.example.com was refused.',
    ],
    'a name that doesn’t resolve' => [
        fn (): Throwable => realFailure('https://nowhere.invalid/v1/secret?token=abc', ['protocols' => ['https'], 'proxy' => ['no' => ['*']]]),
        'The name api.example.com did not resolve.',
    ],
    'no answer in time' => [
        function (): Throwable {
            // Listening, so the connection is made, but never accepted, so no TLS answer comes.
            $silent = stream_socket_server('tcp://127.0.0.1:0');
            $name = (string) stream_socket_get_name($silent, false);
            $port = (int) substr($name, strrpos($name, ':') + 1);

            try {
                return realFailure("https://api.example.com:{$port}/v1/secret?token=abc", pinnedToLocal($port), 1);
            } finally {
                fclose($silent);
            }
        },
        'The request to api.example.com timed out.',
    ],
    'a certificate it can’t trust' => [
        function (): Throwable {
            $server = TlsServer::serving(['index.html' => 'hello']);

            try {
                return realFailure("https://api.example.com:{$server->port}/v1/secret?token=abc", pinnedToLocal($server->port));
            } finally {
                $server->stop();
            }
        },
        'The secure connection to api.example.com failed.',
    ],
    'anything else' => [
        fn (): Throwable => new TransferException('cURL error 56: Recv failure from 127.0.0.1 for https://api.example.com/v1/secret?token=abc', new Psr7Request('GET', 'https://api.example.com/v1/secret?token=abc')),
        'The request to api.example.com failed.',
    ],
    'Guzzle refusing the request' => [
        fn (): Throwable => new GuzzleInvalidArgument('Bad header for https://api.example.com/v1/secret?token=abc: Bearer s3cret-header'),
        'The request to api.example.com failed.',
    ],
]);

it('says only the host and a plain reason when a request gets no answer', function (Throwable $failure, string $words): void {
    $thrown = doorFailingWith($failure);

    expect($thrown)->toBeInstanceOf(RequestFailed::class)
        ->and($thrown->getMessage())->toBe($words)
        ->and($thrown->getPrevious())->toBeNull();

    foreach (['/v1/secret', 'token=abc', 's3cret-header', '127.0.0.1'] as $private) {
        expect($thrown->getMessage())->not->toContain($private);
    }
})->with('failures');

it('lets nothing of Laravel’s or Guzzle’s out of the door', function (Throwable $failure): void {
    $thrown = doorFailingWith($failure);

    for ($cause = $thrown; $cause !== null; $cause = $cause->getPrevious()) {
        expect($cause::class)->toStartWith('Teme\\Contracts\\')
            ->not->toStartWith('Illuminate\\')
            ->not->toStartWith('GuzzleHttp\\');
    }
})->with('failures');

it('gives AnswerTooLarge for an answer stopped past 1 MB as it arrived', function (): void {
    // No Content-Length from this server, so the progress callback stops it.
    $server = TlsServer::serving(['big.bin' => str_repeat('x', PluginHttp::MOST_BYTES + 200_000)]);

    try {
        $failure = realFailure("https://api.example.com:{$server->port}/big.bin", pinnedToLocal($server->port, ['verify' => false]));
    } finally {
        $server->stop();
    }

    $thrown = doorFailingWith($failure, '/big.bin');

    expect($thrown)->toBeInstanceOf(AnswerTooLarge::class)
        ->and($thrown::class)->toStartWith('Teme\\Contracts\\')
        ->and($thrown->getMessage())->toBe('The answer from api.example.com was larger than 1 MB.')
        ->and($thrown->getPrevious())->toBeNull();
});

it('writes the failure to Teme’s log without the query', function (): void {
    Log::spy();

    doorFailingWith(realFailure('https://api.example.com/v1/secret?token=abc', PluginHttp::transport('api.example.com', '127.0.0.1')));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        $logged = $message.json_encode($context, JSON_UNESCAPED_SLASHES);

        return str_contains($logged, 'api.example.com/v1/secret') && str_contains($logged, 'cURL error 7') && ! str_contains($logged, 'token=abc') && ! str_contains($logged, 's3cret-header');
    });
});
