<?php

declare(strict_types=1);

use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function securitySettings(array $overrides): void
{
    SettingsGroupRecord::query()->updateOrCreate(['group_key' => 'security'], ['overrides' => $overrides, 'version' => 1]);
    Cache::memo()->forget(SettingsRepository::cacheKey('security'));
}

function passwordPasses(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();
}

function pwnedRangeFor(string $password, int $count): string
{
    return substr(strtoupper(sha1($password)), 5).':'.$count."\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1";
}

it('takes the minimum length from the security settings', function (): void {
    securitySettings(['password_check_breached' => false]);

    expect(passwordPasses(str_repeat('x', 14).'y'))->toBeTrue();

    securitySettings(['password_check_breached' => false, 'password_min_length' => 20]);

    expect(passwordPasses(str_repeat('x', 14).'y'))->toBeFalse()
        ->and(passwordPasses(str_repeat('x', 19).'y'))->toBeTrue();
});

it('allows up to 256 characters and no more', function (): void {
    securitySettings(['password_check_breached' => false]);

    expect(passwordPasses(str_repeat('ab', 128)))->toBeTrue()
        ->and(passwordPasses(str_repeat('ab', 128).'c'))->toBeFalse();
});

it('refuses a password found in breaches, sending only the first five hash characters', function (): void {
    $password = 'a-perfectly-long-but-leaked-passphrase';
    $prefix = substr(strtoupper(sha1($password)), 0, 5);

    Http::fake(['api.pwnedpasswords.com/*' => Http::response(pwnedRangeFor($password, 42))]);

    expect(passwordPasses($password))->toBeFalse();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === "https://api.pwnedpasswords.com/range/{$prefix}"
        && $request->hasHeader('Add-Padding', 'true'));
});

it('accepts the password and logs a warning when the check times out', function (): void {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('Operation timed out after 3000 milliseconds'));

    expect(passwordPasses('a-perfectly-long-passphrase-here'))->toBeTrue();

    Log::shouldHaveReceived('warning')->once();
});

it('accepts the password and logs a warning when the service answers with an error', function (): void {
    Log::spy();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    expect(passwordPasses('a-perfectly-long-passphrase-here'))->toBeTrue();

    Log::shouldHaveReceived('warning')->once();
});

it('makes no request when the check is switched off', function (): void {
    securitySettings(['password_check_breached' => false]);

    expect(passwordPasses('a-perfectly-long-passphrase-here'))->toBeTrue();

    Http::assertNothingSent();
});
