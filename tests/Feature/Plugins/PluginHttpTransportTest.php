<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\PluginHttp;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;

/*
 * The outbound door's options through the real curl handler: Http::fake()
 * replaces the handler, so only these tests see what Guzzle and libcurl make
 * of them. Nothing leaves the machine: `.invalid` names never resolve
 * (RFC 6761), and nothing listens on 127.0.0.1's port 443.
 */
beforeEach(function (): void {
    Http::allowStrayRequests();
});

/**
 * Sends a GET with the door's options for $host pinned to $address, and
 * returns libcurl's error number for the failure.
 */
function transportErrno(string $host, string $address, string $url): ?int
{
    try {
        Http::setHandler(new CurlHandler)->withOptions(PluginHttp::transport($host, $address))->timeout(2)->get($url);
    } catch (Throwable $failure) {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            expect($cause)->not->toBeInstanceOf(InvalidArgumentException::class);

            if ($cause instanceof TransferException && preg_match('/^cURL error (\d+):/', $cause->getMessage(), $match) === 1) {
                return (int) $match[1];
            }
        }
    }

    return null;
}

it('sends through the real curl handler without Guzzle refusing its options', function (): void {
    // Couldn't connect (7): curl ran with every option the door gives it.
    expect(transportErrno('api.example.com', '127.0.0.1', 'https://api.example.com/'))->toBe(CURLE_COULDNT_CONNECT);
});

it('connects to the pinned address alone, past any proxy the environment names', function (): void {
    $names = ['https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
    $before = array_map(fn (string $name): string|false => getenv($name), array_combine($names, $names));

    foreach ($names as $name) {
        putenv("{$name}=http://proxy.invalid:3128");
    }

    try {
        // Without the pin, curl looks the name up (6, couldn't resolve); through the proxy, it looks the proxy up (5).
        $errno = transportErrno('pinned.invalid', '127.0.0.1', 'https://pinned.invalid/');
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    expect($errno)->toBe(CURLE_COULDNT_CONNECT);
});

it('refuses plain http in the handler itself, before any connection', function (): void {
    expect(fn () => Http::setHandler(new CurlHandler)->withOptions(PluginHttp::transport('api.example.com', '127.0.0.1'))->get('http://api.example.com/'))
        ->toThrow(Exception::class, 'The scheme "http" is not allowed by the protocols request option.');
});
