<?php

declare(strict_types=1);

namespace App\Domain\Users\Security;

use Illuminate\Hashing\Argon2IdHasher;
use SensitiveParameter;

/**
 * Writes Argon2id only. Verifies by the stored hash's format: Teme's own
 * Argon2id and bcrypt, and WordPress's MD5, phpass and bcrypt, so an imported
 * account signs in until its hash is replaced (I-124). Unknown formats never
 * verify.
 */
final class PasswordHasher extends Argon2IdHasher
{
    /**
     * @param  string  $value
     * @param  string|null  $hashedValue  null for an account with no password set
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return false;
        }

        return match (PasswordHashFormat::detect($hashedValue)) {
            PasswordHashFormat::Argon2id => parent::check($value, $hashedValue, $options),
            PasswordHashFormat::Bcrypt => password_verify((string) $value, $hashedValue),
            PasswordHashFormat::WordPressBcrypt => WordPressHashes::checkBcrypt((string) $value, $hashedValue),
            PasswordHashFormat::Phpass => WordPressHashes::checkPhpass((string) $value, $hashedValue),
            PasswordHashFormat::Md5 => WordPressHashes::checkMd5((string) $value, $hashedValue),
            null => false,
        };
    }
}
