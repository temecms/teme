<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Remote;

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Signatures\Signer;
use App\Domain\Media\Downloads\SizeLimit;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Network\CheckedAddress;
use App\Domain\Support\Network\PublicAddresses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a remote fediverse document, guarded against reaching inside the
 * server's network (SSRF): https only, every address checked public and
 * pinned, each redirect checked again, at most three of them, five seconds
 * and a megabyte at most, signed as the application actor when there is
 * one. The document's id must be on the host that answered.
 */
final readonly class RemoteFetcher
{
    public const int MAX_BYTES = 1_048_576;

    public const int MAX_REDIRECTS = 3;

    public const int SECONDS = 5;

    public const string ACCEPT = 'application/activity+json';

    /** The answers that name somewhere else to go. */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private PublicAddresses $addresses,
        private Signer $signer,
    ) {}

    /**
     * The document at the address, decoded.
     *
     * @return array<string, mixed>
     *
     * @throws FetchRefused
     */
    public function get(string $url, ?FediverseActor $signAs = null): array
    {
        $signAs ??= FediverseActor::query()->where('kind', FediverseActor::APPLICATION)->where('enabled', true)->first();
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $checked = $this->check($current);
            $limit = new SizeLimit(self::MAX_BYTES);
            $headers = ['Accept' => self::ACCEPT];

            if ($signAs !== null) {
                $headers = [...$headers, ...$this->signer->sign('GET', $current, [], '', $signAs)];
            }

            try {
                $response = Http::withOptions(self::options($checked, $limit))
                    ->connectTimeout(self::SECONDS)
                    ->timeout(self::SECONDS)
                    ->withUserAgent('Teme')
                    ->withHeaders($headers)
                    ->get($current);
            } catch (ConnectionException|RequestException) {
                throw FetchRefused::because($limit->exceeded ? 'The answer is too big.' : 'The server could not be reached.');
            }

            $status = $response->status();
            $location = trim($response->header('Location'));

            if (in_array($status, self::REDIRECT_STATUSES, true) && $location !== '') {
                $current = self::follow($current, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw FetchRefused::because('The server answered '.$status.'.', $status);
            }

            $body = $response->body();

            if ($limit->exceeded || strlen($body) > self::MAX_BYTES) {
                throw FetchRefused::because('The answer is too big.');
            }

            return self::document($body, $current);
        }

        throw FetchRefused::because('Too many redirects.');
    }

    /**
     * The address checked: https, public, and pinned.
     *
     * @throws FetchRefused
     */
    private function check(string $url): CheckedAddress
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw FetchRefused::because('Only https addresses are fetched.');
        }

        try {
            return $this->addresses->check($url);
        } catch (AddressRefused) {
            throw FetchRefused::because('The address is not a public one.');
        }
    }

    /**
     * Guzzle's options: no redirect followed by Guzzle, no proxy, so the pin
     * and the check apply to the host itself, and the size limit.
     *
     * @return array<string, mixed>
     */
    private static function options(CheckedAddress $address, SizeLimit $limit): array
    {
        $options = ['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https'], 'progress' => $limit];
        $pin = $address->curlResolve();

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        return $options;
    }

    /**
     * Where a redirect leads, read against the address that sent it.
     */
    private static function follow(string $from, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }

    /**
     * The answer as a document whose id is on the host that answered.
     *
     * @return array<string, mixed>
     *
     * @throws FetchRefused
     */
    private static function document(string $body, string $url): array
    {
        $document = json_decode($body, true);

        if (! is_array($document) || ! is_string($document['id'] ?? null)) {
            throw FetchRefused::because('The answer is not a document.');
        }

        $answered = strtolower((string) parse_url($url, PHP_URL_HOST));
        $named = strtolower((string) parse_url($document['id'], PHP_URL_HOST));

        if ($named === '' || $named !== $answered) {
            throw FetchRefused::because('The document names another server.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
