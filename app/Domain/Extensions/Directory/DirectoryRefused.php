<?php

/**
 * Why Teme won't use what the plugin and theme directory sent, with the
 * words people read; its message is its sentence and its reason a key.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Directory;

use RuntimeException;

final class DirectoryRefused extends RuntimeException
{
    public const string OFF = 'off';

    public const string NO_KEY = 'no-key';

    public const string UNREACHABLE = 'unreachable';

    public const string TOO_LARGE = 'too-large';

    public const string SIGNATURE = 'signature';

    public const string MALFORMED = 'malformed';

    public const string OLDER = 'older';

    public const string EXPIRED = 'expired';

    public const string TAMPERED = 'tampered';

    public const string NOT_LISTED = 'not-listed';

    public const string NOT_YET = 'not-yet';

    private function __construct(public readonly string $reason, string $sentence)
    {
        parent::__construct($sentence);
    }

    /** The directory is switched off on this site. */
    public static function off(): self
    {
        return new self(self::OFF, __('The plugin and theme directory is switched off on this site.'));
    }

    /** This Teme holds no directory key it can check a signature with. */
    public static function noKey(): self
    {
        return new self(self::NO_KEY, __('This Teme has no key to check the directory’s signature with, so it reads nothing from the directory.'));
    }

    /** The directory didn't answer, or not with the file asked for. */
    public static function unreachable(string $file): self
    {
        return new self(self::UNREACHABLE, __('The directory didn’t answer with :file. Try again later.', ['file' => $file]));
    }

    /** A file was larger than Teme reads. */
    public static function tooLarge(string $file): self
    {
        return new self(self::TOO_LARGE, __('The directory’s :file is larger than Teme reads, so it was refused.', ['file' => $file]));
    }

    /** The index's signature isn't one of the directory's keys. */
    public static function signature(): self
    {
        return new self(self::SIGNATURE, __('The directory’s list isn’t signed with the directory’s key, so it was refused. It may have been changed on the way.'));
    }

    /** A file isn't in the shape the directory writes. */
    public static function malformed(string $file): self
    {
        return new self(self::MALFORMED, __('The directory’s :file isn’t in the shape Teme reads, so it was refused.', ['file' => $file]));
    }

    /** The index is older than one already seen: someone may be replaying it. */
    public static function older(int $sequence, int $seen): self
    {
        return new self(self::OLDER, __('The directory sent an older list (number :sequence) than one Teme has already seen (number :seen), so it was refused.', ['sequence' => $sequence, 'seen' => $seen]));
    }

    /** The index's expiry has passed. */
    public static function expired(string $expires): self
    {
        return new self(self::EXPIRED, __('The directory’s list ran out on :date and no newer one has come, so Teme won’t install from it.', ['date' => $expires]));
    }

    /** A file's bytes don't match the hash the signed index gives. */
    public static function tampered(string $file): self
    {
        return new self(self::TAMPERED, __('The directory’s :file doesn’t match its signed list, so it was refused. It may have been changed on the way.', ['file' => $file]));
    }

    /** No index was verified and kept yet. */
    public static function notYet(): self
    {
        return new self(self::NOT_YET, __('Teme hasn’t read the directory’s list yet. It reads it about once an hour.'));
    }

    /** The package isn't in the directory's list, or not as this kind. */
    public static function notListed(string $package): self
    {
        return new self(self::NOT_LISTED, __(':name isn’t in the directory’s list.', ['name' => $package]));
    }
}
