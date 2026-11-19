<?php

/**
 * The Two-factor sign-in page: whether it is on, turning it on or off, and
 * the recovery codes. Turning it on and off is Fortify's own (I-100).
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Users\Queries\AccountSecurityStatus;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

final class TwoFactorController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request, AccountSecurityStatus $status): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $status->for((int) $request->user()?->getAuthIdentifier())['twoFactorEnabled'];
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('account/two-factor', $props);
    }
}
