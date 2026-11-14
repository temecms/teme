<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What a column's ciphertext holds inside, as the code that writes it puts
 * it there. Re-encryption copies those bytes as they are, so the form never
 * changes; the registry names it so the tests can prove each reader opens
 * what is written (I-119).
 */
enum EncryptedForm: string
{
    /** encryptString(), or the `encrypted` cast: the value itself. */
    case String = 'string';

    /** The `encrypted:array` cast and its kin: JSON, through encryptString(). */
    case Json = 'json';

    /** encrypt(), as Fortify writes: PHP's serialized string. */
    case Serialized = 'serialized';
}
