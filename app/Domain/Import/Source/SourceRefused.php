<?php

declare(strict_types=1);

namespace App\Domain\Import\Source;

use RuntimeException;

/**
 * Why a source can't be read, with the field it belongs to, whether trying
 * again could help, and one plain sentence. Its message is its sentence; it
 * never carries a URL with a credential, or the site's own error text.
 */
final class SourceRefused extends RuntimeException
{
    public const string UNREACHABLE = 'unreachable';

    public const string BUSY = 'busy';

    public const string MOVED = 'moved';

    public const string NOT_COMPANION = 'not-companion';

    public const string TOO_OLD = 'too-old';

    public const string NO_APPLICATION_PASSWORDS = 'no-application-passwords';

    public const string CREDENTIALS = 'credentials';

    public const string NOT_ADMINISTRATOR = 'not-administrator';

    public const string HTTPS_ONLY = 'https';

    public const string BLOCKED = 'blocked';

    public const string BAD_ANSWER = 'bad-answer';

    private function __construct(
        public readonly string $reason,
        public readonly string $field,
        public readonly bool $retryable,
        private readonly string $words,
    ) {
        parent::__construct($words);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE, 'address', true, 'Teme couldn’t reach the site. Check the address, and that the site is up.');
    }

    public static function busy(int $status): self
    {
        return new self(self::BUSY, 'address', true, "The site is busy or had an error ({$status}). Try again in a minute.");
    }

    public static function moved(?string $location): self
    {
        $parts = $location === null ? false : parse_url($location);
        $target = '';

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $target = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        $words = $target === ''
            ? 'The site sent Teme on to another address. Use the address in WordPress’s Settings › General.'
            : "The site sent Teme on to {$target}. Use that address instead.";

        return new self(self::MOVED, 'address', false, $words);
    }

    public static function notCompanion(): self
    {
        return new self(self::NOT_COMPANION, 'address', false, 'The Teme Companion plugin isn’t active on this site. Install and activate it, then try again.');
    }

    public static function tooOld(int $api): self
    {
        return new self(self::TOO_OLD, 'address', false, 'This site’s Teme Companion plugin is too old for Teme. Update it, then try again.');
    }

    public static function noApplicationPasswords(): self
    {
        return new self(self::NO_APPLICATION_PASSWORDS, 'address', false, 'Application passwords are switched off on this site, so Teme can’t sign in. WordPress offers them only over HTTPS.');
    }

    public static function credentials(): self
    {
        return new self(self::CREDENTIALS, 'application_password', false, 'WordPress didn’t accept this user name and application password. If both are right, the site’s server may be removing the sign-in header before WordPress sees it.');
    }

    public static function notAdministrator(): self
    {
        return new self(self::NOT_ADMINISTRATOR, 'user_name', false, 'This WordPress account isn’t an administrator. Use an administrator’s user name, and an application password made for that account.');
    }

    public static function blocked(): self
    {
        return new self(self::BLOCKED, 'address', false, 'Something in front of WordPress, such as a firewall or Cloudflare’s bot check, blocked Teme before WordPress answered. Let Teme’s server through, then try again.');
    }

    public static function httpsOnly(): self
    {
        return new self(self::HTTPS_ONLY, 'address', false, 'The Teme Companion plugin answers only over HTTPS. Use https:// in the address.');
    }

    public static function badAnswer(): self
    {
        return new self(self::BAD_ANSWER, 'address', false, 'The site’s answer wasn’t one the Teme Companion plugin sends. Check the address.');
    }

    /**
     * The words people read for this refusal.
     */
    public function sentence(): string
    {
        return $this->words;
    }
}
