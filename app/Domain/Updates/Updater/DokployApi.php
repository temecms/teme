<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Calls one procedure of Dokploy's API with the owner's key. Every failure becomes a PlatformRefused built
 * from Teme's own words and the status, since Dokploy's body, and Laravel's exception carrying it, may echo the key
 * or a registry's login.
 */
final class DokployApi
{
    /** The procedures that change something and whose answer needs no body. */
    private const array WRITES = ['application.update', 'application.redeploy'];

    /**
     * @param  array<string, string>  $query
     *
     * @throws PlatformRefused
     */
    public function get(string $procedure, array $query = []): mixed
    {
        return $this->call('GET', $procedure, $query);
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws PlatformRefused
     */
    public function post(string $procedure, #[SensitiveParameter] array $data): mixed
    {
        return $this->call('POST', $procedure, $data);
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws PlatformRefused
     */
    private function call(string $method, string $procedure, #[SensitiveParameter] array $data): mixed
    {
        $token = DokployToken::value() ?? throw new PlatformRefused('No Dokploy API key is set. Add one in Settings › Updates.');
        $address = $this->address($procedure);

        try {
            $response = Http::withHeaders(['x-api-key' => $token])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(30)
                ->send($method, $address, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException|TransferException) {
            // TransferException is Guzzle's root: whatever the transport throws, its message may carry the request.
            throw new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
        }

        return match (true) {
            $response->successful() => $this->readable($response->json(), $response->body(), $response->status(), $procedure),
            in_array($response->status(), [401, 403], true) => throw new PlatformRefused('Dokploy refused the API key. Check it in Settings › Updates.'),
            $response->redirect() => throw new PlatformRefused("Dokploy answered {$response->status()}, sending Teme elsewhere; Teme doesn’t follow it."),
            default => throw new PlatformRefused("Dokploy answered {$response->status()} to {$procedure}."),
        };
    }

    /**
     * Returns the address of one API procedure: https, or http to a name without a dot on Docker's own network.
     *
     * @throws PlatformRefused
     */
    private function address(string $procedure): string
    {
        $url = config('teme.updater.dokploy.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $secure = $scheme === 'https' || ($scheme === 'http' && $host !== '' && ! str_contains($host, '.'));

        if (! is_string($url) || ! is_array($parts) || $host === '' || ! $secure || isset($parts['user']) || isset($parts['pass'])) {
            throw new PlatformRefused('TEME_DOKPLOY_URL must start with https://, or http:// to a name without a dot on Docker’s own network.');
        }

        return rtrim($url, '/')."/api/{$procedure}";
    }

    /**
     * Returns the decoded body of a 2xx answer; the calls that write need no body, the ones that read need it to be JSON.
     */
    private function readable(#[SensitiveParameter] mixed $json, #[SensitiveParameter] string $body, int $status, string $procedure): mixed
    {
        if (! in_array($procedure, self::WRITES, true) && $json === null && trim($body) !== 'null') {
            throw new PlatformRefused("Dokploy answered {$status} to {$procedure}.");
        }

        return $json;
    }
}
