<?php

/**
 * The code the first-run page asks for (issue 246): derived from the site's
 * application key, so only someone who can read the deploy log or run a
 * teme: command has it, and nothing is stored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users;

use RuntimeException;
use SensitiveParameter;

final readonly class SetupCode
{
    /** Crockford's base 32: no I, L, O or U, so it reads aloud and types cleanly. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const string PURPOSE = 'teme.first-run-setup';

    /**
     * This site's code: 80 bits derived from the key by HKDF, never a plain
     * HMAC, which other code makes of typed input with the same key, as 16
     * characters in four groups of four.
     */
    public function forThisSite(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('The application key is missing, so there is no setup code.');
        }

        $bits = '';

        foreach (str_split(hash_hkdf('sha256', $key, 10, self::PURPOSE)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $code = '';

        foreach (str_split($bits, 5) as $chunk) {
            $code .= self::ALPHABET[(int) bindec($chunk)];
        }

        return implode('-', str_split($code, 4));
    }

    /**
     * Whether $typed is this site's code, ignoring case, spaces and hyphens,
     * and reading O as 0 and I or L as 1.
     */
    public function matches(#[SensitiveParameter] string $typed): bool
    {
        $normal = strtr(strtoupper((string) preg_replace('/[\s-]+/', '', $typed)), ['O' => '0', 'I' => '1', 'L' => '1']);

        return hash_equals(str_replace('-', '', $this->forThisSite()), $normal);
    }
}
