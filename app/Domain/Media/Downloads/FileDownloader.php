<?php

/**
 * Downloads one file from another site into a temporary file, safely
 * (I-453): http or https with no password in the address; every host it
 * connects to checked public and pinned, each redirect's too, followed by
 * hand; the media library's size limit held as the bytes arrive; a time
 * limit over the whole download, its tries included; a certificate checked,
 * never switched off; the type read from the bytes, never the name or the
 * header. It stores nothing: the caller hands the file to UploadMedia, then
 * releases it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Downloads;

use App\Domain\Media\FileTypes;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Network\CheckedAddress;
use App\Domain\Support\Network\PublicAddresses;
use finfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final readonly class FileDownloader
{
    /** The seconds a download may take by default, its tries and waits included. */
    public const int SECONDS = 20;

    /** The most seconds a caller may give a download. */
    public const int MOST_SECONDS = 60;

    /** The redirects followed before giving up, as many as WordPress's own requests follow. */
    public const int REDIRECTS = 5;

    /** The seconds waited before the second and the third try. */
    private const array WAITS = [1, 3];

    /** The most seconds a connection may take to open. */
    private const int CONNECT_SECONDS = 10;

    /** A redirect that names where to go. */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** cURL's errors for a certificate its check refused: 51 before libcurl 7.62, 60, 83 an issuer, 91 a certificate's status (I-453). */
    private const array CERTIFICATE_ERRORS = [51, 60, 83, 91];

    public function __construct(
        private PublicAddresses $addresses,
        private SettingsReader $settings,
        private ?string $folder = null,
    ) {}

    /**
     * The file at this address, in a temporary file the caller releases.
     * Never call it inside a transaction: it waits on another site.
     *
     * @throws DownloadRefused when Teme won't, or couldn't, download it; nothing is left behind
     */
    public function download(string $address, bool $imagesOnly = false, int $seconds = self::SECONDS): DownloadedFile
    {
        $address = trim($address);
        $seconds = max(1, min($seconds, self::MOST_SECONDS));
        $deadline = (int) hrtime(true) + $seconds * 1_000_000_000;
        $maxBytes = $this->settings->int('media', 'max_upload_mb') * 1024 * 1024;
        $sink = tempnam($this->folder ?? sys_get_temp_dir(), 'teme-download-');

        if ($sink === false) {
            throw new RuntimeException('Teme couldn’t make a temporary file for a download.');
        }

        try {
            for ($try = 0; ; $try++) {
                try {
                    $this->fetch($address, $sink, $maxBytes, $deadline);

                    break;
                } catch (DownloadRefused $refusal) {
                    $wait = self::WAITS[$try] ?? null;

                    if (! $refusal->retryable || $refusal->reason === DownloadRefused::CERTIFICATE || $wait === null || self::secondsLeft($deadline) < $wait + 1) {
                        throw $refusal;
                    }

                    Sleep::for($wait)->seconds();
                }
            }

            return $this->inspect($address, $sink, $maxBytes, $imagesOnly);
        } catch (Throwable $failure) {
            if (is_file($sink)) {
                unlink($sink);
            }

            throw $failure;
        }
    }

    /**
     * Whether a site answers at this address: one HEAD request, checked and
     * pinned as a download is, any answer below 500 counting, a redirect
     * included. An import's plan asks it of an old site before it offers to
     * download its media files (docs/architecture/import.md).
     *
     * @since next
     */
    public function answers(string $address, int $seconds = 5): bool
    {
        $sink = tempnam($this->folder ?? sys_get_temp_dir(), 'teme-answer-');

        if ($sink === false) {
            return false;
        }

        try {
            $checked = $this->check(trim($address));
            $response = Http::withOptions(self::options($checked, new SizeLimit(1024 * 1024), $sink))
                ->connectTimeout(max(1, min(self::CONNECT_SECONDS, $seconds)))
                ->timeout(max(1, $seconds))
                ->withUserAgent('Teme')
                ->head(trim($address));

            return $response->status() < 500;
        } catch (DownloadRefused|ConnectionException|RequestException) {
            return false;
        } finally {
            if (is_file($sink)) {
                unlink($sink);
            }
        }
    }

    /**
     * A request's options: pinned to the checked address; no proxy, so the
     * pin and the check apply to the host itself; no redirect followed by
     * Guzzle; the bytes as sent, so a compressed answer can't grow on disk;
     * written to the sink, and stopped once they pass the limit. Public for
     * its test.
     *
     * @return array<string, mixed>
     */
    public static function options(CheckedAddress $address, SizeLimit $limit, string $sink): array
    {
        $options = [
            'allow_redirects' => false,
            'proxy' => '',
            'protocols' => ['http', 'https'],
            'decode_content' => false,
            'progress' => $limit,
            'sink' => $sink,
        ];
        $pin = $address->curlResolve();

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        return $options;
    }

    /**
     * Fetches the address into the sink, following up to five redirects by
     * hand, each one checked and pinned before Teme connects to it.
     *
     * @throws DownloadRefused
     */
    private function fetch(string $address, string $sink, int $maxBytes, int $deadline): void
    {
        $current = $address;

        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $checked = $this->check($current);
            $host = $checked->host === '' ? $checked->ip : $checked->host;
            $left = self::secondsLeft($deadline);

            // Less than a second left is no time to fetch a file; Guzzle refuses a timeout below a millisecond.
            if ($left < 1) {
                throw DownloadRefused::unreachable($host);
            }

            $limit = new SizeLimit($maxBytes);

            try {
                $response = Http::withOptions(self::options($checked, $limit, $sink))
                    ->connectTimeout(min(self::CONNECT_SECONDS, $left))
                    ->timeout($left)
                    ->withUserAgent('Teme')
                    ->withHeaders(['Accept' => '*/*'])
                    ->get($current);
            } catch (ConnectionException|RequestException $failure) {
                throw match (true) {
                    $limit->exceeded => DownloadRefused::tooLarge($maxBytes),
                    self::untrusted($failure) => DownloadRefused::certificate($host),
                    default => DownloadRefused::unreachable($host),
                };
            }

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                return;
            }

            $location = trim($response->header('Location'));

            if (in_array($status, self::REDIRECT_STATUSES, true) && $location !== '') {
                $current = self::follow($current, $location);

                continue;
            }

            throw DownloadRefused::forStatus($host, $status);
        }

        throw DownloadRefused::redirects();
    }

    /**
     * The address, checked by the public-address check and pinned. Plain
     * http is allowed: the check refuses it to a public host only to keep a
     * password off the wire, and a download sends none (I-453).
     *
     * @throws DownloadRefused
     */
    private function check(string $address): CheckedAddress
    {
        try {
            return $this->addresses->check($address, plainHttp: true);
        } catch (AddressRefused $refusal) {
            throw DownloadRefused::because($refusal);
        }
    }

    /**
     * The downloaded file, judged by its bytes alone.
     *
     * @throws DownloadRefused
     */
    private function inspect(string $address, string $sink, int $maxBytes, bool $imagesOnly): DownloadedFile
    {
        clearstatcache(true, $sink);
        $size = (int) filesize($sink);

        if ($size === 0) {
            throw DownloadRefused::emptyFile();
        }

        if ($size > $maxBytes) {
            throw DownloadRefused::tooLarge($maxBytes);
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($sink);
        $name = self::nameOf($address);
        $type = FileTypes::for($mime, $name);

        if ($type === null) {
            throw $mime === 'text/html' ? DownloadRefused::webPage() : DownloadRefused::notAccepted();
        }

        if ($imagesOnly && $type['kind'] !== 'image') {
            throw DownloadRefused::notImage();
        }

        return new DownloadedFile($sink, $name, $mime, $type['kind'], $size, self::provenance($address));
    }

    /**
     * Where a redirect leads, read against the address that sent it: a full
     * address, one without its scheme, a path, a query, or a name beside the
     * current one.
     */
    private static function follow(string $from, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($from);
        $base = is_array($base) ? $base : [];
        $scheme = strtolower($base['scheme'] ?? 'https');
        $origin = $scheme.'://'.($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');
        $path = $base['path'] ?? '/';

        return match (true) {
            str_starts_with($location, '//') => $scheme.':'.$location,
            str_starts_with($location, '/') => $origin.$location,
            str_starts_with($location, '?') => $origin.$path.$location,
            default => $origin.(string) preg_replace('#[^/]*$#', '', $path).$location,
        };
    }

    /**
     * The file's name: the last part of the address's path, decoded, valid
     * UTF-8 with no control characters and no slash, at most 255 bytes,
     * where UploadMedia cuts it, never half a character, and "download" when
     * there is none, since PostgreSQL refuses a zero byte or broken UTF-8.
     */
    private static function nameOf(string $address): string
    {
        $last = basename((string) parse_url($address, PHP_URL_PATH));
        $name = (string) preg_replace('/[[:cntrl:]]/u', '', mb_scrub(rawurldecode($last), 'UTF-8'));
        $name = trim(str_replace(['/', '\\'], '-', $name));

        return $name === '' ? 'download' : mb_strcut($name, 0, 255, 'UTF-8');
    }

    /**
     * Where the file came from (I-453): the scheme and host lower-cased, any
     * port, and the path; never a user name, a query or a place, so a signed
     * address's token isn't kept. Null when longer than 2,048 characters.
     */
    private static function provenance(string $address): ?string
    {
        $parts = parse_url($address);
        $parts = is_array($parts) ? $parts : [];
        $source = strtolower($parts['scheme'] ?? '').'://'.strtolower($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/');

        return strlen($source) <= 2048 ? $source : null;
    }

    /**
     * The seconds left before the download's deadline.
     */
    private static function secondsLeft(int $deadline): float
    {
        return ($deadline - (int) hrtime(true)) / 1_000_000_000;
    }

    /**
     * Whether cURL refused the other site's certificate. Guzzle 8 keeps
     * cURL's error number only in its message, "cURL error 60: …".
     */
    private static function untrusted(Throwable $failure): bool
    {
        return preg_match('/^cURL error (\d+):/', $failure->getMessage(), $error) === 1
            && in_array((int) $error[1], self::CERTIFICATE_ERRORS, true);
    }
}
