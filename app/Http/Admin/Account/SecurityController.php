<?php

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Users\Operations\ChangePassword;
use App\Domain\Users\Operations\Input\ChangePasswordInput;
use App\Domain\Users\Queries\AccountSecurityStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * The Security page: changing your password and setting up two-factor sign-in.
 * A password change goes to the `ChangePassword` Operation; two-factor is
 * Fortify's own.
 */
final class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request, AccountSecurityStatus $status): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $status->for((int) $request->user()?->getAuthIdentifier())['twoFactorEnabled'];
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('account/security', $props);
    }

    public function update(Request $request, ChangePassword $changePassword): RedirectResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $changePassword->execute(new ChangePasswordInput(
            userId: $actorId,
            currentPassword: $request->string('current_password')->toString(),
            password: $request->string('password')->toString(),
            passwordConfirmation: $request->string('password_confirmation')->toString(),
            actorId: $actorId,
        ));

        // AuthenticateSession compares this session's password fingerprint
        // with the user's. Reload the user so this session records the new
        // one and survives, while every other session ends.
        $request->user()?->refresh();
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password changed. You’re signed out everywhere else.')]);

        return back();
    }
}
