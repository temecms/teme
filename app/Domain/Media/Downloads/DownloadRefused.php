<?php

/**
 * Why a file wasn't downloaded, in the words people read, in the editor's
 * Import images and in an import's report; its message is its sentence.
 * Retryable when trying again later could help.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Downloads;

use App\Domain\Support\Network\AddressRefused;
use RuntimeException;

final class DownloadRefused extends RuntimeException
{
    public const string ADDRESS = 'address';

    public const string UNKNOWN_HOST = 'unknown-host';

    public const string NOT_PUBLIC = 'not-public';

    public const string UNREACHABLE = 'unreachable';

    /**
     * The other site's HTTPS certificate failed Teme's check: retryable, so a
     * full import tries again once it is fixed; never fetched over plain http
     * or unchecked.
     */
    public const string CERTIFICATE = 'certificate';

    public const string BUSY = 'busy';

    public const string GONE = 'gone';

    public const string FORBIDDEN = 'forbidden';

    public const string STATUS = 'status';

    public const string REDIRECTS = 'redirects';

    public const string TOO_LARGE = 'too-large';

    public const string EMPTY = 'empty';

    public const string WEB_PAGE = 'web-page';

    public const string NOT_ACCEPTED = 'not-accepted';

    public const string NOT_IMAGE = 'not-image';

    private function __construct(
        public readonly string $reason,
        public readonly bool $retryable,
        string $words,
    ) {
        parent::__construct($words);
    }

    public function sentence(): string
    {
        return $this->getMessage();
    }

    public static function address(): self
    {
        return new self(self::ADDRESS, false, 'Teme can download only from an address that starts with http:// or https://, with no user name or password in it.');
    }

    /**
     * The public-address check's refusal: its own words where they fit a
     * download, else the address's.
     */
    public static function because(AddressRefused $refusal): self
    {
        return match ($refusal->reason) {
            AddressRefused::UNKNOWN_HOST => new self(self::UNKNOWN_HOST, false, $refusal->sentence()),
            AddressRefused::NOT_PUBLIC => new self(self::NOT_PUBLIC, false, $refusal->sentence()),
            default => self::address(),
        };
    }

    public static function unreachable(string $host): self
    {
        return new self(self::UNREACHABLE, true, "Teme couldn’t reach {$host}, or it took too long to answer. Try again later.");
    }

    public static function certificate(string $host): self
    {
        return new self(self::CERTIFICATE, true, "Teme couldn’t download it securely: {$host}’s HTTPS certificate isn’t trusted (expired, self-signed or for another name). Fix the certificate on that site, then try again.");
    }

    /**
     * An answer that isn't the file, by its status.
     */
    public static function forStatus(string $host, int $status): self
    {
        return match (true) {
            $status === 408 || $status === 429 || $status >= 500 => new self(self::BUSY, true, "{$host} is busy or had an error ({$status}). Try again later."),
            $status === 404 || $status === 410 => new self(self::GONE, false, "{$host} has no file at this address ({$status})."),
            $status === 401 || $status === 403 => new self(self::FORBIDDEN, false, "{$host} won’t let Teme download this file ({$status})."),
            default => new self(self::STATUS, false, "{$host} answered {$status} instead of sending the file."),
        };
    }

    public static function redirects(): self
    {
        return new self(self::REDIRECTS, false, 'The address sent Teme on more than five times, so Teme stopped.');
    }

    /**
     * The words FileInspector uses for an upload over the limit.
     */
    public static function tooLarge(int $maxBytes): self
    {
        return new self(self::TOO_LARGE, false, 'The file is larger than the '.intdiv($maxBytes, 1024 * 1024).' MB limit.');
    }

    public static function emptyFile(): self
    {
        return new self(self::EMPTY, false, 'The file is empty.');
    }

    public static function webPage(): self
    {
        return new self(self::WEB_PAGE, false, 'This address leads to a web page, not a file.');
    }

    public static function notAccepted(): self
    {
        return new self(self::NOT_ACCEPTED, false, 'This file isn’t a type the media library accepts.');
    }

    public static function notImage(): self
    {
        return new self(self::NOT_IMAGE, false, 'This file isn’t an image.');
    }
}
