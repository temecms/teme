<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Plugins\HostPattern;
use Closure;
use Exception;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;

/**
 * A plugin's only way out (http, I-3353): https to port 443, to a host its
 * consent covers, resolved here and refused when any address is private or
 * reserved, connected to that address only (no second lookup, so DNS can't
 * be rebound), never following a redirect, within 10 seconds and 1 MB each
 * way, with a JSON or a form body. The lookup comes before those 10 seconds and has no limit of its own:
 * the system resolver's, by default 5 seconds a try, two tries a server, for
 * up to three servers, so as long as 30 seconds when DNS is down.
 */
final readonly class PluginHttp implements OutboundHttp
{
    public const int MOST_SECONDS = 10;

    public const int MOST_BYTES = 1_048_576;

    /**
     * libcurl's errors for a TLS handshake or certificate that failed: 35,
     * 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90 and 91.
     */
    private const array SECURE_CONNECTION_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * @param  list<string>  $hosts  the hosts this plugin's consent covers
     * @param  int  $maxBytes  answer size cap; defaults to 1 MB for http, media may pass the upload limit
     * @param  int  $mostSeconds  the upper limit for each call: 10 normally, 300 inside a long job (I-3445)
     */
    public function __construct(
        private array $hosts,
        private HostResolver $resolver,
        private int $maxBytes = self::MOST_BYTES,
        private int $mostSeconds = self::MOST_SECONDS,
    ) {}

    public function get(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        return $this->send('GET', $url, null, '', $headers, $timeoutSeconds);
    }

    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        return $this->send('POST', $url, fn (string $host): string => self::jsonBody($json, $host, $this->maxBytes), 'application/json', $headers, $timeoutSeconds);
    }

    public function postForm(string $url, array $fields, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        self::checkFields($fields);

        return $this->send('POST', $url, fn (string $host): string => self::formBody($fields, $host, $this->maxBytes), 'application/x-www-form-urlencoded', $headers, $timeoutSeconds);
    }

    /**
     * @param  (Closure(string): string)|null  $body  the body for the host checked, built before any lookup
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, ?Closure $body, string $contentType, array $headers, int $timeoutSeconds): HttpAnswer
    {
        // Parsed once, by the parser Guzzle sends with, so the host checked is the host connected to.
        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            throw new HostRefused(__('It may send requests only to the hosts you agreed to: :hosts.', ['hosts' => implode(', ', $this->hosts)]));
        }

        $host = $this->allowedHost($uri);
        $body = $body === null ? null : $body($host);
        $address = $this->publicAddress($host);
        $seconds = max(1, min($timeoutSeconds, $this->mostSeconds));

        $request = Http::withHeaders(array_filter($headers, fn (string $name): bool => strcasecmp($name, 'host') !== 0 && ($contentType !== 'application/x-www-form-urlencoded' || strcasecmp($name, 'content-type') !== 0), ARRAY_FILTER_USE_KEY))
            ->setHandler(new CurlHandler)
            ->withoutRedirecting()
            ->timeout($seconds)
            ->connectTimeout(min($seconds, 5))
            ->withOptions(self::transport($host, $address, $this->maxBytes));

        try {
            $response = $method === 'GET' ? $request->get((string) $uri) : $request->withBody((string) $body, $contentType)->post((string) $uri);
        } catch (Exception $failure) {
            throw self::failure($failure, $host, $uri);
        }

        $body = $response->body();

        if (strlen($body) > $this->maxBytes) {
            throw new AnswerTooLarge(self::tooLargeWords($host, $this->maxBytes));
        }

        return new HttpAnswer($response->status(), $body, $response->headers());
    }

    /**
     * Guzzle's options for one request: curl only, never Guzzle's `stream`
     * option, which hands the request to PHP's stream handler and drops
     * every curl option, the pin with them; connected to $address alone, the
     * one curl option Guzzle lets through for it; https alone and no proxy,
     * whatever the environment names, through Guzzle's own options, which
     * set curl's; no answer decoded, so a small compressed one can't grow;
     * and no answer past $maxBytes, stopped as its headers say so or as it
     * arrives.
     *
     * @return array<string, mixed>
     */
    public static function transport(string $host, string $address, int $maxBytes = self::MOST_BYTES): array
    {
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;
        $tooLarge = fn (): AnswerTooLarge => new AnswerTooLarge(self::tooLargeWords($host, $maxBytes));

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"],
            ],
            'protocols' => ['https'],
            'proxy' => ['no' => ['*']],
            'decode_content' => false,
            'on_headers' => function (ResponseInterface $answer) use ($tooLarge, $maxBytes): void {
                if ((int) $answer->getHeaderLine('Content-Length') > $maxBytes) {
                    throw $tooLarge();
                }
            },
            // Guzzle stops the transfer when this throws, and keeps what it threw.
            'progress' => function (int $downloadTotal, int $downloaded) use ($tooLarge, $maxBytes): bool {
                if ($downloaded > $maxBytes) {
                    throw $tooLarge();
                }

                return false;
            },
        ];
    }

    /**
     * Returns $json as the body to send, refused before any lookup or
     * connection when it passes 1 MB.
     *
     * @param  array<string, mixed>  $json
     */
    private static function jsonBody(array $json, string $host, int $maxBytes = self::MOST_BYTES): string
    {
        try {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RequestFailed(__('The request to :host failed.', ['host' => $host]));
        }

        if (strlen($body) > $maxBytes) {
            throw new RequestFailed(__('The request to :host was larger than :size MB, so Teme didn’t send it.', [
                'host' => $host,
                'size' => max(1, intdiv($maxBytes, 1024 * 1024)),
            ]));
        }

        return $body;
    }

    /**
     * Refuses, before any lookup, a field that is neither a string nor a
     * list of strings, or one without a name.
     *
     * @param  array<array-key, mixed>  $fields
     */
    private static function checkFields(array $fields): void
    {
        foreach ($fields as $name => $value) {
            $values = is_array($value) && array_is_list($value) ? $value : [$value];

            if (! is_string($name) || array_any($values, fn (mixed $one): bool => ! is_string($one))) {
                throw new InvalidArgumentException('A form’s fields are names with a string or a list of strings.');
            }
        }
    }

    /**
     * Returns the fields as a form body, each value of a list under its own
     * name again, refused before any lookup or connection when it passes 1 MB.
     *
     * @param  array<string, string|list<string>>  $fields
     */
    private static function formBody(array $fields, string $host, int $maxBytes): string
    {
        $pairs = [];

        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                // Form encoding: a space is +.
                $pairs[] = urlencode($name).'='.urlencode($one);
            }
        }

        $body = implode('&', $pairs);

        if (strlen($body) > $maxBytes) {
            throw new RequestFailed(__('The request to :host was larger than :size MB, so Teme didn’t send it.', [
                'host' => $host,
                'size' => max(1, intdiv($maxBytes, 1024 * 1024)),
            ]));
        }

        return $body;
    }

    private static function tooLargeWords(string $host, int $maxBytes): string
    {
        return __('The answer from :host was larger than :size MB.', [
            'host' => $host,
            'size' => max(1, intdiv($maxBytes, 1024 * 1024)),
        ]);
    }

    /**
     * Turns what Guzzle or Laravel's client threw into the contract's own
     * exception, naming the host and a plain reason alone: the path, query,
     * headers and the address connected to aren't the plugin's to read, so
     * nothing is chained. The original goes to Teme's log, without the query.
     */
    private static function failure(Exception $failure, string $host, Uri $uri): AnswerTooLarge|RequestFailed
    {
        $errno = null;
        $timedOut = false;
        $logged = [];

        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AnswerTooLarge) {
                return new AnswerTooLarge($cause->getMessage() !== '' ? $cause->getMessage() : self::tooLargeWords($host, self::MOST_BYTES));
            }

            if ($errno === null && preg_match('/^cURL error (\d+):/', $cause->getMessage(), $match) === 1) {
                $errno = (int) $match[1];
            }

            $timedOut = $timedOut || $cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException;
            $logged[] = $cause::class.': '.self::withoutQueries($cause->getMessage());
        }

        $words = match (true) {
            $timedOut || $errno === CURLE_OPERATION_TIMEOUTED => __('The request to :host timed out.', ['host' => $host]),
            $errno === CURLE_COULDNT_CONNECT => __('The connection to :host was refused.', ['host' => $host]),
            $errno === CURLE_COULDNT_RESOLVE_HOST => __('The name :host did not resolve.', ['host' => $host]),
            in_array($errno, self::SECURE_CONNECTION_ERRORS, true) => __('The secure connection to :host failed.', ['host' => $host]),
            default => __('The request to :host failed.', ['host' => $host]),
        };

        logger()->warning('A plugin’s request got no answer.', [
            'url' => (string) $uri->withQuery('')->withFragment(''),
            'reason' => $words,
            'causes' => $logged,
        ]);

        return new RequestFailed($words);
    }

    /** Returns $message with the query cut from every address in it. */
    private static function withoutQueries(string $message): string
    {
        return (string) preg_replace('~(\bhttps?://[^\s?#]*)[?#]\S*~i', '$1', $message);
    }

    private function allowedHost(Uri $uri): string
    {
        if (strtolower($uri->getScheme()) !== 'https') {
            throw new HostRefused(__('Teme sends requests only over https.'));
        }

        if ($uri->getUserInfo() !== '') {
            throw new HostRefused(__('An address may not carry a name or password.'));
        }

        if (($uri->getPort() ?? 443) !== 443) {
            throw new HostRefused(__('Teme sends requests only to port 443.'));
        }

        $host = strtolower($uri->getHost());

        if (preg_match(HostPattern::PATTERN, $host) !== 1 || str_starts_with($host, '*.') || ! HostPattern::anyCovers($this->hosts, $host)) {
            throw new HostRefused(__('It may send requests only to the hosts you agreed to: :hosts.', ['hosts' => implode(', ', $this->hosts)]));
        }

        return $host;
    }

    private function publicAddress(string $host): string
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new HostRefused(__(':host has no address.', ['host' => $host]));
        }

        foreach ($addresses as $address) {
            if (! PublicAddress::is($address)) {
                throw new HostRefused(__(':host points to a private address, so Teme won’t send to it.', ['host' => $host]));
            }
        }

        return $addresses[0];
    }
}
