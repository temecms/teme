<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;

it('hashes new passwords with Argon2id', function (): void {
    expect(Hash::make('correct-horse-battery-staple'))->toStartWith('$argon2id$');
});

it('does not truncate long passwords the way bcrypt does', function (): void {
    // bcrypt ignores everything after byte 72, so these two would verify as
    // the same password. Study G, S2.
    $password = str_repeat('a', 72).'X';
    $differsOnlyAtByte73 = str_repeat('a', 72).'Y';

    expect(Hash::check($differsOnlyAtByte73, Hash::make($password)))->toBeFalse();
});

it('still verifies bcrypt hashes written before this slice, and wants them rehashed', function (): void {
    $legacy = password_hash('correct-horse-battery-staple', PASSWORD_BCRYPT, ['cost' => 4]);

    expect(Hash::check('correct-horse-battery-staple', $legacy))->toBeTrue()
        ->and(Hash::check('wrong-horse-battery-staple', $legacy))->toBeFalse()
        ->and(Hash::needsRehash($legacy))->toBeTrue();
});

it('refuses hash formats it does not know', function (string $hash): void {
    expect(Hash::check('anything', $hash))->toBeFalse();
})->with([
    'MD5-crypt' => [crypt('anything', '$1$teme1234$')],
    'plain text' => ['anything'],
    'empty' => [''],
]);

it('never rehashes on sign-in from vendor code', function (): void {
    expect(config('hashing.rehash_on_login'))->toBeFalse();
});
