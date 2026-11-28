<?php

/**
 * A plugin's only way out (http, I-3353): https to port 443, to a host its
 * consent covers, resolved here and refused when any address is private or
 * reserved, connected to that address only (no second lookup, so DNS can't
 * be rebound), never following a redirect, within 10 seconds and 1 MB each
 * way, with a JSON or a form body, or a multipart or raw body of up to 10 MB;
 * inside a long job's run, 300 seconds and an answer of 16 MB. The lookup comes before those 10 seconds and has no limit of its own:
 * the system resolver's, by default 5 seconds a try, two tries a server, for
 * up to three servers, so as long as 30 seconds when DNS is down.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Plugins\Downloads\DownloadFolders;
use App\Domain\Plugins\HostPattern;
use Closure;
use Exception;
use FilesystemIterator;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\HttpDownload;
use Teme\Contracts\Http\HttpFile;
use Teme\Contracts\Http\OutboundDownloads;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;

final readonly class PluginHttp implements OutboundDownloads, OutboundHttp
{
    public const int MOST_SECONDS = 10;

    /** The largest file a download may hold, in bytes. */
    public const int MOST_DOWNLOAD_BYTES = 134_217_728;

    /** The most a plugin's downloads may hold together, in bytes. */
    public const int MOST_FOLDER_BYTES = 536_870_912;

    /** Redirects followed for one request. */
    public const int MOST_REDIRECTS = 5;

    public const int MOST_BYTES = 1_048_576;

    /**
     * The largest body a multipart or raw request may send; the answer's
     * limit stays at MOST_BYTES.
     *
     * @since 1.7.0
     */
    public const int MOST_REQUEST_BYTES = 10_485_760;

    /**
     * The largest answer inside a long job's run; a request stays at MOST_BYTES.
     *
     * @since 1.0.0
     */
    public const int LONG_ANSWER_BYTES = 16_777_216;

    /**
     * libcurl's errors for a TLS handshake or certificate that failed: 35,
     * 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90 and 91.
     */
    private const array SECURE_CONNECTION_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * @param  list<string>  $hosts  the hosts this plugin's consent covers
     * @param  int  $maxBytes  answer size cap; defaults to 1 MB for http, 16 MB inside a long job, media may pass the upload limit; a request's body stays at 1 MB
     * @param  int  $mostSeconds  the upper limit for each call: 10 normally, 300 inside a long job (I-3445)
     * @param  (Closure(): list<string>)|null  $settingHosts  the exact hosts agreed in its settings, read at each call (docs/plans/plugin-sockets-1.md)
     * @param  string|null  $ownPath  the plugin's own folder, the only place a multipart file may be read from
     * @param  string|null  $downloadFolder  the plugin's private folder for downloads, which holds nothing else; null refuses a download
     * @param  int  $downloadBytes  the largest file a download may hold, from the manifest's downloads (16 MB by default)
     * @param  int  $folderBytes  the most the folder may hold together
     */
    public function __construct(
        private array $hosts,
        private HostResolver $resolver,
        private int $maxBytes = self::MOST_BYTES,
        private int $mostSeconds = self::MOST_SECONDS,
        private ?Closure $settingHosts = null,
        private ?string $ownPath = null,
        private ?string $downloadFolder = null,
        private int $downloadBytes = self::LONG_ANSWER_BYTES,
        private int $folderBytes = self::MOST_FOLDER_BYTES,
    ) {}

    /**
     * @since next
     */
    public function head(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        return $this->follow('HEAD', $url, $headers, $timeoutSeconds, $this->maxBytes, null);
    }

    /**
     * @since next
     */
    public function download(string $url, array $headers = [], int $timeoutSeconds = 60): HttpDownload
    {
        if ($this->downloadFolder === null) {
            throw new RequestFailed(__('Downloads work only inside a long job.'));
        }

        DownloadFolders::sweepFolder($this->downloadFolder);

        // The stream stops at whichever is smaller: the manifest's file limit, or the room the plugin's whole folder has left.
        $room = $this->folderBytes - $this->folderSize();

        if ($room <= 0) {
            throw new RequestFailed(__('The plugin’s downloads already fill its folder, so Teme didn’t download the file.'));
        }

        if (! is_dir($this->downloadFolder) && ! mkdir($this->downloadFolder, 0700, true) && ! is_dir($this->downloadFolder)) {
            throw new RequestFailed(__('Teme couldn’t make the folder for downloads.'));
        }

        $path = $this->downloadFolder.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.download';

        try {
            $answer = $this->follow('GET', $url, $headers, $timeoutSeconds, min($this->downloadBytes, $room), $path);
        } catch (Exception $failure) {
            @unlink($path);

            throw $failure;
        }

        clearstatcache(true, $path);

        return new HttpDownload($path, (int) filesize($path), $answer->status, $this->header($answer, 'content-type'));
    }

    public function get(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        return $this->send('GET', $url, null, '', $headers, $timeoutSeconds);
    }

    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        return $this->send('POST', $url, fn (string $host): string => self::jsonBody($json, $host), 'application/json', $headers, $timeoutSeconds);
    }

    /**
     * @since 1.0.0
     */
    public function postForm(string $url, array $fields, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        self::checkFields($fields);

        return $this->send('POST', $url, fn (string $host): string => self::formBody($fields, $host, self::MOST_BYTES), 'application/x-www-form-urlencoded', $headers, $timeoutSeconds, true);
    }

    /**
     * @since 1.7.0
     */
    public function postMultipart(string $url, array $fields, array $files, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        self::checkFields($fields);
        $sizes = array_map(fn (mixed $file): int => $this->fileSize($file), $files);
        $boundary = 'teme-'.bin2hex(random_bytes(16));

        return $this->send('POST', $url, fn (string $host): string => self::multipartBody($fields, $files, $sizes, $boundary, $host), 'multipart/form-data; boundary='.$boundary, $headers, $timeoutSeconds, true);
    }

    /**
     * @since 1.7.0
     */
    public function postRaw(string $url, string $body, string $contentType, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        if (trim($contentType) === '' || preg_match('/[\r\n]/', $contentType) === 1) {
            throw new InvalidArgumentException('A raw body needs a content type on one line.');
        }

        return $this->send('POST', $url, function (string $host) use ($body): string {
            if (strlen($body) > self::MOST_REQUEST_BYTES) {
                throw self::requestTooLarge($host);
            }

            return $body;
        }, $contentType, $headers, $timeoutSeconds, true);
    }

    /**
     * @param  (Closure(string): string)|null  $body  the body for the host checked, built before any lookup
     * @param  array<string, string>  $headers
     * @param  bool  $ownContentType  the body's content type is the one sent, so one the plugin passes is dropped
     */
    private function send(string $method, string $url, ?Closure $body, string $contentType, array $headers, int $timeoutSeconds, bool $ownContentType = false, ?int $answerBytes = null, ?string $sink = null): HttpAnswer
    {
        $limit = $answerBytes ?? $this->maxBytes;

        // Parsed once, by the parser Guzzle sends with, so the host checked is the host connected to.
        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            throw new HostRefused(__('It may send requests only to the hosts you agreed to: :hosts.', ['hosts' => implode(', ', $this->agreedHosts())]));
        }

        $host = $this->allowedHost($uri);
        $body = $body === null ? null : $body($host);
        $address = $this->publicAddress($host);
        $seconds = max(1, min($timeoutSeconds, $this->mostSeconds));

        $request = Http::withHeaders(array_filter($headers, fn (string $name): bool => strcasecmp($name, 'host') !== 0 && (! $ownContentType || strcasecmp($name, 'content-type') !== 0), ARRAY_FILTER_USE_KEY))
            ->setHandler(new CurlHandler)
            ->withoutRedirecting()
            ->timeout($seconds)
            ->connectTimeout(min($seconds, 5))
            ->withOptions(self::transport($host, $address, $limit));

        // A download's bytes go straight to its file, which the transport's progress check keeps within the limit.
        if ($sink !== null) {
            $request = $request->withOptions(['sink' => $sink]);
        }

        try {
            $response = match ($method) {
                'GET' => $request->get((string) $uri),
                'HEAD' => $request->head((string) $uri),
                default => $request->withBody((string) $body, $contentType)->post((string) $uri),
            };
        } catch (Exception $failure) {
            throw self::failure($failure, $host, $uri);
        }

        $answered = $sink === null ? $response->body() : '';
        $size = $sink === null ? strlen($answered) : (int) (is_file($sink) ? filesize($sink) : 0);

        if ($size > $limit) {
            throw new AnswerTooLarge(self::tooLargeWords($host, $limit));
        }

        return new HttpAnswer($response->status(), $answered, $response->headers());
    }

    /**
     * Makes one request, then follows its redirects (a download's and a HEAD's
     * only), each hop checked as a first request is, until an answer that is
     * not a redirect, or until MOST_REDIRECTS have been followed.
     *
     * @param  array<string, string>  $headers
     */
    private function follow(string $method, string $url, array $headers, int $timeoutSeconds, int $limit, ?string $sink): HttpAnswer
    {
        $current = $url;
        $previous = null;

        for ($followed = 0; ; $followed++) {
            // A hop to another scheme, host or port gets none of the plugin's headers: no Authorization, no Cookie, nothing it set.
            $here = new Uri($current);
            $sameOrigin = $previous === null || (strtolower($here->getScheme()) === strtolower($previous->getScheme()) && strtolower($here->getHost()) === strtolower($previous->getHost()) && ($here->getPort() ?? 443) === ($previous->getPort() ?? 443));
            $answer = $this->send($method, $current, null, '', $sameOrigin ? $headers : [], $timeoutSeconds, false, $limit, $sink);
            $previous = $here;

            if (! in_array($answer->status, [301, 302, 303, 307, 308], true)) {
                return $answer;
            }

            $location = $this->header($answer, 'location');

            if ($followed >= self::MOST_REDIRECTS || $location === '') {
                throw new RequestFailed(__('Too many redirects from :host, so Teme stopped.', ['host' => (string) parse_url($current, PHP_URL_HOST)]));
            }

            // A relative location is resolved against the address that sent it; the host check then runs on the result.
            $current = (string) UriResolver::resolve(new Uri($current), new Uri($location));
        }
    }

    /**
     * The first value of a header, by its name in any case, or '' when none.
     */
    private function header(HttpAnswer $answer, string $name): string
    {
        foreach ($answer->headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0 && $values !== []) {
                return (string) $values[0];
            }
        }

        return '';
    }

    /**
     * The bytes the plugin's download folder holds now.
     */
    private function folderSize(): int
    {
        if ($this->downloadFolder === null || ! is_dir($this->downloadFolder)) {
            return 0;
        }

        $total = 0;

        foreach (new FilesystemIterator($this->downloadFolder, FilesystemIterator::SKIP_DOTS) as $file) {
            $total += $file->isFile() ? $file->getSize() : 0;
        }

        return $total;
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

    /**
     * Returns the bytes of a multipart body, refused before any lookup or
     * connection when it passes 10 MB, each file read only once its size, and
     * the sizes before it, are known to fit.
     *
     * @param  array<string, string|list<string>>  $fields
     * @param  list<HttpFile>  $files
     * @param  list<int>  $sizes  each file's size in bytes, in order
     */
    private static function multipartBody(array $fields, array $files, array $sizes, string $boundary, string $host): string
    {
        $parts = [];
        $total = 0;

        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $parts[] = '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".self::quoted($name)."\"\r\n\r\n".$one."\r\n";
                $total += strlen($one);
            }
        }

        $total += array_sum($sizes) + (count($files) + count($parts)) * (strlen($boundary) + 200);

        if ($total > self::MOST_REQUEST_BYTES) {
            throw self::requestTooLarge($host);
        }

        foreach ($files as $file) {
            $parts[] = '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".self::quoted($file->field).'"; filename="'.self::quoted($file->filename)."\"\r\nContent-Type: ".$file->contentType."\r\n\r\n".($file->bytes ?? (string) file_get_contents((string) $file->path))."\r\n";
        }

        $body = implode('', $parts).'--'.$boundary."--\r\n";

        if (strlen($body) > self::MOST_REQUEST_BYTES) {
            throw self::requestTooLarge($host);
        }

        return $body;
    }

    /**
     * A field or file name as a multipart header carries it: a quote or a
     * line break written as its percent code, as a browser writes it.
     */
    private static function quoted(string $name): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $name);
    }

    /**
     * Returns a file's size in bytes, refused before any lookup when the
     * file isn't a file, a path isn't inside the plugin's own folder, or
     * the content type has a line break in it.
     */
    private function fileSize(mixed $file): int
    {
        if (! $file instanceof HttpFile) {
            throw new InvalidArgumentException('A multipart request’s files are HttpFile values.');
        }

        if (preg_match('/[\r\n]/', $file->contentType) === 1) {
            throw new InvalidArgumentException('A file’s content type is on one line.');
        }

        if ($file->bytes !== null) {
            return strlen($file->bytes);
        }

        $own = $this->ownPath === null ? false : realpath($this->ownPath);
        $path = realpath((string) $file->path);

        if ($own === false || $path === false || ! is_file($path) || ! str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $own), '/').'/')) {
            throw new InvalidArgumentException('A file’s path must be a file inside the plugin’s own folder.');
        }

        return (int) filesize($path);
    }

    private static function requestTooLarge(string $host): RequestFailed
    {
        return new RequestFailed(__('The request to :host was larger than :size MB, so Teme didn’t send it.', [
            'host' => $host,
            'size' => intdiv(self::MOST_REQUEST_BYTES, 1024 * 1024),
        ]));
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

        if (preg_match(HostPattern::PATTERN, $host) !== 1 || str_starts_with($host, '*.') || ! HostPattern::anyCovers($this->agreedHosts(), $host)) {
            throw new HostRefused(__('It may send requests only to the hosts you agreed to: :hosts.', ['hosts' => implode(', ', $this->agreedHosts())]));
        }

        return $host;
    }

    /**
     * The hosts its consent covers, then the exact hosts agreed in its
     * settings that its settings name now.
     *
     * @return list<string>
     */
    private function agreedHosts(): array
    {
        $more = $this->settingHosts === null ? [] : ($this->settingHosts)();

        return array_values(array_unique([...$this->hosts, ...array_filter($more, fn (string $host): bool => ! str_starts_with($host, '*.'))]));
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
