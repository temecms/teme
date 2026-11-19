<?php

/**
 * The password readers give for an item protected by one (I-1700): its
 * rules; sealed with the application key, so whoever sets it can read it
 * back to share it; opened only here; and the key a reader's cookie holds
 * for it once they have given it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

final class ReaderPasswords
{
    /** The longest password, in characters, as WordPress's post_password holds. */
    public const int MOST = 255;

    /**
     * Why the password can't be kept, or null when it can: 1 to 255
     * characters of UTF-8, at least one of them not a space, kept as typed.
     */
    public static function problem(#[SensitiveParameter] string $password): ?string
    {
        if (! mb_check_encoding($password, 'UTF-8') || trim($password) === '' || mb_strlen($password) > self::MOST) {
            return __('Enter a password of up to 255 characters.');
        }

        return null;
    }

    /**
     * The password as the column keeps it: encrypted, never serialized (rule 4).
     */
    public static function seal(#[SensitiveParameter] string $password): string
    {
        return Crypt::encryptString($password);
    }

    /**
     * The item's password as it was typed; null when the item isn't
     * protected, or its password can't be read with this site's key.
     */
    public static function open(Content $content): ?string
    {
        $sealed = $content->getAttribute('reader_password');

        if ($content->visibility !== Visibility::Protected || ! is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Whether the password given is the item's, exactly as typed. Both are
     * hashed first, so the comparison takes the same time whatever their
     * lengths.
     */
    public static function matches(Content $content, #[SensitiveParameter] string $given): bool
    {
        $password = self::open($content);

        if ($password === null) {
            return false;
        }

        $key = (string) config('app.key');

        return hash_equals(hash_hmac('sha256', $password, $key), hash_hmac('sha256', $given, $key));
    }

    /**
     * The key that opens the item for a reader who gave its password: 22
     * characters, new whenever the password or the site's key changes, so
     * either change asks every reader again; null when nothing opens it.
     */
    public static function key(Content $content): ?string
    {
        $password = self::open($content);

        if ($password === null) {
            return null;
        }

        $mac = hash_hmac('sha256', "teme.unlock\0{$content->id}\0{$password}", (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
    }

    /**
     * The key for the item with this ID when the password given is its
     * own, or null: the item gone, not protected, its password unreadable,
     * or the wrong one. For the unlock endpoint, which holds no model.
     */
    public static function keyFor(string $contentId, #[SensitiveParameter] string $given): ?string
    {
        $content = Content::query()->find($contentId);

        return $content !== null && self::matches($content, $given) ? self::key($content) : null;
    }

    /**
     * What an import's fingerprint takes of the password: a keyed hash, so
     * nothing stored beside it lets anyone guess the password; '' when the
     * item has none, or it can't be read.
     */
    public static function fingerprint(Content $content): string
    {
        $password = self::open($content);

        return $password === null ? '' : hash_hmac('sha256', "teme.password\0{$password}", (string) config('app.key'));
    }
}
