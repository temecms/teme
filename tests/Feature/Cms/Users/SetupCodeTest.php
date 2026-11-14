<?php

declare(strict_types=1);

use App\Domain\Users\SetupCode;

it('gives this site one code, sixteen characters in four groups of four', function (): void {
    $code = app(SetupCode::class)->forThisSite();

    expect($code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/')
        ->and(app(SetupCode::class)->forThisSite())->toBe($code);
});

it('gives another site, with another key, another code', function (): void {
    $code = app(SetupCode::class)->forThisSite();

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(app(SetupCode::class)->forThisSite())->not->toBe($code);
});

it('accepts the code typed loosely: lower case, spaces, no hyphens, O for 0 and I or L for 1', function (): void {
    $setupCode = app(SetupCode::class);
    $code = $setupCode->forThisSite();
    $loose = strtolower(str_replace('-', ' ', strtr($code, ['0' => 'O', '1' => 'l'])));

    expect($setupCode->matches($code))->toBeTrue()
        ->and($setupCode->matches(str_replace('-', '', $code)))->toBeTrue()
        ->and($setupCode->matches(" {$loose} "))->toBeTrue();
});

it('refuses any other code, an empty one and a near miss', function (): void {
    $setupCode = app(SetupCode::class);
    $code = $setupCode->forThisSite();
    $last = $code[-1];
    $nearMiss = substr($code, 0, -1).($last === 'Z' ? 'Y' : 'Z');

    expect($setupCode->matches(''))->toBeFalse()
        ->and($setupCode->matches($nearMiss))->toBeFalse()
        ->and($setupCode->matches(substr($code, 0, -1)))->toBeFalse()
        ->and($setupCode->matches($code.'0'))->toBeFalse();
});

it('is not the HMAC other code makes of typed input with the same key', function (): void {
    $code = app(SetupCode::class)->forThisSite();
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $bits = '';

    foreach (str_split(substr(hash_hmac('sha256', 'teme.first-run-setup', (string) config('app.key'), true), 0, 10)) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $hmac = '';

    foreach (str_split($bits, 5) as $chunk) {
        $hmac .= $alphabet[(int) bindec($chunk)];
    }

    expect(str_replace('-', '', $code))->not->toBe($hmac);
});
