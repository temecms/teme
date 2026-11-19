<?php

/**
 * The Security page: changing your password, through the `ChangePassword`
 * Operation, and whether two-factor sign-in is on. Setting two-factor
 * sign-in up has its own page (TwoFactorController).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

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

final class SecurityController extends Controller
{
    public function edit(Request $request, AccountSecurityStatus $status): Response
    {
        $available = Features::canManageTwoFactorAuthentication();

        return Inertia::render('account/security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            // Security's two-factor panel: whether it can be on, and whether it is.
            'canManageTwoFactor' => $available,
            'twoFactorEnabled' => $available && $status->for((int) $request->user()?->getAuthIdentifier())['twoFactorEnabled'],
        ]);
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
