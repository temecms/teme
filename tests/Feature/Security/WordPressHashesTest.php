<?php

declare(strict_types=1);

use App\Domain\Users\Security\PasswordHashFormat;
use Illuminate\Support\Facades\Hash;

/*
 * The WordPress hashes here were made by WordPress's own phpass class and by
 * wp_hash_password()'s formula, for "correct horse battery staple" unless a
 * row says otherwise. Bcrypt rows use cost 04, to keep the suite quick.
 */

function wphPassword(): string
{
    return 'correct horse battery staple';
}

/**
 * "pässwörd ünïcode", as its UTF-8 bytes.
 */
function wphUnicodePassword(): string
{
    return (string) hex2bin('70c3a4737377c3b6726420c3bc6ec3af63c3b66465');
}

it('verifies each WordPress format with the right password and refuses a wrong one', function (string $hash): void {
    expect(Hash::check(wphPassword(), $hash))->toBeTrue()
        ->and(Hash::check('correct horse battery stapler', $hash))->toBeFalse()
        ->and(Hash::check('correct  horse battery staple', $hash))->toBeFalse()
        ->and(Hash::check('', $hash))->toBeFalse();
})->with([
    'phpass as WordPress wrote it, 2^13 rounds' => ['$P$BTeme1234mc6/ctael236960/qKEKM1'],
    'phpass at its fewest rounds, 2^7' => ['$P$5Teme1234CBGSsDMLsbLXr7v6V9Ss1.'],
    'phpass at the most rounds Teme accepts, 2^16' => ['$P$ETeme1234XFF7HwnReH9ZvRa21n1MU1'],
    'WordPress bcrypt' => ['$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'],
    'MD5' => ['9cc2ae8a1ba7a93da39b46fc1019c481'],
]);

it('verifies a password with letters beyond ASCII, byte for byte', function (string $hash): void {
    expect(Hash::check(wphUnicodePassword(), $hash))->toBeTrue()
        ->and(Hash::check('passwordunicode', $hash))->toBeFalse();
})->with([
    'phpass' => ['$P$BTeme5678jUycDo/GBduISQmsYcMIQ1'],
    'WordPress bcrypt' => ['$wp$2y$04$5VzV0sjckqx.PcFubVWiNelYn0KjbqLoh438Kf2gSg6CmYxcXQb5y'],
    'MD5' => [md5(wphUnicodePassword())],
]);

it('forgives spaces around the password for WordPress formats only', function (): void {
    $padded = "  correct horse battery staple \t";

    expect(Hash::check($padded, '$P$BTeme1234mc6/ctael236960/qKEKM1'))->toBeTrue()
        ->and(Hash::check($padded, '$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'))->toBeTrue()
        ->and(Hash::check($padded, md5(wphPassword())))->toBeTrue()
        ->and(Hash::check($padded, Hash::make(wphPassword())))->toBeFalse()
        ->and(Hash::check($padded, password_hash(wphPassword(), PASSWORD_BCRYPT, ['cost' => 4])))->toBeFalse();
});

it('refuses a password over 4,096 bytes for phpass and WordPress bcrypt, but not for MD5', function (string $hash, int $length, bool $verifies): void {
    expect(Hash::check(str_repeat('a', $length), $hash))->toBe($verifies);
})->with([
    'phpass, 4,096 bytes' => ['$P$BTeme1234QqVuUGPhWGZ.pPyEprySF/', 4096, true],
    'phpass, 4,097 bytes, though the hash is theirs' => ['$P$BTeme12345JutAYboG31ScEF8ycneK.', 4097, false],
    'WordPress bcrypt, 4,096 bytes' => ['$wp$2y$04$L6ohYc2KSLWSrIpIbmU42uHLnwPGc./KvlpUg3jfuh32pfqy4p4P6', 4096, true],
    'WordPress bcrypt, 4,097 bytes, though the hash is theirs' => ['$wp$2y$04$/6KtXa16QLurXdSLZTmHv.9N8fGRIkVdliwfHnDxrfxmhz2itYrZ2', 4097, false],
    'MD5, 4,097 bytes' => [md5(str_repeat('a', 4097)), 4097, true],
]);

it('refuses formats PHP would verify but Teme does not know', function (string $hash): void {
    expect(password_verify(wphPassword(), $hash))->toBeTrue()
        ->and(PasswordHashFormat::detect($hash))->toBeNull()
        ->and(Hash::check(wphPassword(), $hash))->toBeFalse();
})->with([
    'crypt(3) DES, which reads eight characters' => [crypt('correct horse battery staple', 'te')],
    'extended DES' => [crypt('correct horse battery staple', '_J9..teme')],
    'MD5-crypt' => [crypt('correct horse battery staple', '$1$teme1234$')],
    'SHA-512 crypt' => [crypt('correct horse battery staple', '$6$rounds=5000$teme1234salt$')],
    'bcrypt 2a' => [crypt('correct horse battery staple', '$2a$04$abcdefghijklmnopqrstuv')],
    'bcrypt 2b' => [crypt('correct horse battery staple', '$2b$04$abcdefghijklmnopqrstuv')],
    'Argon2i' => [password_hash('correct horse battery staple', PASSWORD_ARGON2I, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])],
]);

it('refuses the WordPress prefix in front of anything but 2y bcrypt', function (): void {
    $prehashed = base64_encode(hash_hmac('sha384', wphPassword(), 'wp-sha384', true));
    $hash = '$wp'.crypt($prehashed, '$2a$04$abcdefghijklmnopqrstuv');

    expect(password_verify($prehashed, substr($hash, 3)))->toBeTrue()
        ->and(Hash::check(wphPassword(), $hash))->toBeFalse();
});

it('recognises no hash whose work factor is outside the bounds', function (string $hash): void {
    expect(PasswordHashFormat::detect($hash))->toBeNull();
})->with([
    'phpass at 2^6 rounds, below phpass itself' => ['$P$4Teme1234CBGSsDMLsbLXr7v6V9Ss1.'],
    'phpass at 2^17 rounds' => ['$P$FTeme1234dNmyIs7rvhspYdylkO65U.'],
    'phpass at 2^30 rounds' => ['$P$STeme1234dNmyIs7rvhspYdylkO65U.'],
    'bcrypt at cost 03' => ['$2y$03$abcdefghijklmnopqrstuu7EJV7kdjBBQxyb0HjTh9KS7.Lah/6CG'],
    'bcrypt at cost 17' => ['$2y$17$abcdefghijklmnopqrstuu7EJV7kdjBBQxyb0HjTh9KS7.Lah/6CG'],
    'WordPress bcrypt at cost 31' => ['$wp$2y$31$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'],
]);

it('refuses phpass at 2^17 rounds though the password is right', function (): void {
    // WordPress's own phpass made this hash, for this password.
    expect(Hash::check(wphPassword(), '$P$FTeme1234dNmyIs7rvhspYdylkO65U.'))->toBeFalse();
});

it('refuses what WordPress refuses too', function (string $hash): void {
    expect(Hash::check(wphPassword(), $hash))->toBeFalse();
})->with([
    'phpBB phpass' => ['$H$9Teme1234A0mMujkPAQM0mjvNNHXgT0'],
    'MD5 in capitals' => [strtoupper(md5('correct horse battery staple'))],
    'MD5 with a line break after it' => [md5('correct horse battery staple')."\n"],
    'the password itself' => ['correct horse battery staple'],
    'nothing' => [''],
]);

it('names each format it recognises', function (string $hash, PasswordHashFormat $format): void {
    expect(PasswordHashFormat::detect($hash))->toBe($format);
})->with([
    'Argon2id' => [password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]), PasswordHashFormat::Argon2id],
    'bcrypt' => [password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]), PasswordHashFormat::Bcrypt],
    'WordPress bcrypt' => ['$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C', PasswordHashFormat::WordPressBcrypt],
    'phpass' => ['$P$BTeme1234mc6/ctael236960/qKEKM1', PasswordHashFormat::Phpass],
    'MD5' => ['9cc2ae8a1ba7a93da39b46fc1019c481', PasswordHashFormat::Md5],
]);

it('wants every older hash replaced', function (string $hash): void {
    expect(Hash::needsRehash($hash))->toBeTrue();
})->with([
    'WordPress bcrypt' => ['$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'],
    'phpass' => ['$P$BTeme1234mc6/ctael236960/qKEKM1'],
    'MD5' => ['9cc2ae8a1ba7a93da39b46fc1019c481'],
    'bcrypt' => [password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4])],
    'Argon2id at other costs' => [password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 512, 'time_cost' => 1, 'threads' => 1])],
]);

it('leaves Argon2id at the configured costs as it is', function (): void {
    expect(Hash::needsRehash(Hash::make(wphPassword())))->toBeFalse();
});
