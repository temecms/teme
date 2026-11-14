<?php

declare(strict_types=1);

use App\Domain\Users\Queries\AccountSecurityStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports whether the account must verify its email and has two-factor', function (): void {
    $plain = User::factory()->create();
    $secured = User::factory()->withTwoFactor()->create();

    expect(app(AccountSecurityStatus::class)->for($plain->id))->toBe(['mustVerifyEmail' => true, 'twoFactorEnabled' => false])
        ->and(app(AccountSecurityStatus::class)->for($secured->id))->toBe(['mustVerifyEmail' => true, 'twoFactorEnabled' => true]);
});
