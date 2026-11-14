<?php

declare(strict_types=1);

namespace App\Domain\Users\Security;

use SensitiveParameter;

/**
 * Verifies the three password hash formats WordPress has written, the way
 * WordPress checks them, so an imported account signs in until its hash is
 * replaced (I-124). Ported from WordPress's behaviour, not its code, and
 * every comparison is timing-safe.
 */
final class WordPressHashes
{
    /** WordPress refuses a longer password before phpass or bcrypt runs. */
    private const int LONGEST_PASSWORD = 4096;

    /** phpass's alphabet, in phpass's order: a character's place is its value. */
    private const string ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * WordPress before 2.5: the password's MD5, in lowercase hexadecimal.
     */
    public static function checkMd5(#[SensitiveParameter] string $password, string $hash): bool
    {
        return hash_equals($hash, md5(trim($password)));
    }

    /**
     * WordPress 2.5 to 6.7: phpass's portable hash. `$P$`, the count
     * character, eight salt characters, then 22 characters of digest.
     */
    public static function checkPhpass(#[SensitiveParameter] string $password, string $hash): bool
    {
        $password = trim($password);

        if (strlen($password) > self::LONGEST_PASSWORD) {
            return false;
        }

        return hash_equals($hash, substr($hash, 0, 12).self::phpassDigest($password, $hash));
    }

    /**
     * WordPress 6.8 and later: `$wp`, then bcrypt of the base 64 of the
     * password's HMAC-SHA384 under the key `wp-sha384`.
     */
    public static function checkBcrypt(#[SensitiveParameter] string $password, string $hash): bool
    {
        $password = trim($password);

        if (strlen($password) > self::LONGEST_PASSWORD) {
            return false;
        }

        $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

        return password_verify($prehashed, substr($hash, 3));
    }

    /**
     * The 22 characters phpass writes after the salt: the MD5 of the salt and
     * the password, then as many rounds of the MD5 of that digest and the
     * password as the count character says.
     */
    private static function phpassDigest(#[SensitiveParameter] string $password, string $hash): string
    {
        $rounds = 1 << (int) strpos(self::ALPHABET, $hash[3]);
        $digest = md5(substr($hash, 4, 8).$password, true);

        for ($round = 0; $round < $rounds; $round++) {
            $digest = md5($digest.$password, true);
        }

        return self::phpassBase64($digest);
    }

    /**
     * phpass's own base 64, not the usual one: each group of three bytes is
     * read low byte first and written six bits at a time from the lowest, and
     * a short last group writes one character more than it has bytes.
     */
    private static function phpassBase64(string $bytes): string
    {
        $encoded = '';

        foreach (str_split($bytes, 3) as $group) {
            $value = 0;

            foreach (str_split($group) as $position => $byte) {
                $value |= ord($byte) << (8 * $position);
            }

            for ($sextet = 0; $sextet <= strlen($group); $sextet++) {
                $encoded .= self::ALPHABET[($value >> (6 * $sextet)) & 0x3F];
            }
        }

        return $encoded;
    }
}
