<?php

/**
 * The page where an invited person chooses their password. It shows the form
 * and hands the choice to the `AcceptInvitation` Operation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Queries\InvitationSummary;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationController extends Controller
{
    public function show(Request $request, string $token, InvitationSummary $summary): Response
    {
        $email = $request->string('email')->toString();

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            // Who invited them, as what, to which site: only for a link that still works.
            'invitation' => $summary->for($email, $token),
        ]);
    }

    public function store(Request $request, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        try {
            $acceptInvitation->execute(new AcceptInvitationInput(
                email: $request->string('email')->toString(),
                token: $request->string('token')->toString(),
                password: $request->string('password')->toString(),
                passwordConfirmation: $request->string('password_confirmation')->toString(),
                name: $request->filled('name') ? $request->string('name')->toString() : null,
            ));
        } catch (AuthorizationException) {
            throw ValidationException::withMessages([
                'email' => __('This invitation link no longer works. Ask an administrator to send a new one.'),
            ]);
        }

        return to_route('login')->with('status', __('Your password is set. Sign in to continue.'));
    }
}
