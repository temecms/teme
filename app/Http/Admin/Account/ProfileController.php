<?php

/**
 * The Profile page. It shows the signed-in person's name, email and bio,
 * and hands changes to the `UpdateProfile` Operation; it changes nothing
 * itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Addresses\Addresses;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Domain\Users\Queries\AccountSecurityStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    public function edit(Request $request, AccountSecurityStatus $status): Response
    {
        $user = $request->user();
        $authorPath = $user === null ? null : Addresses::relativeUrl($user);

        return Inertia::render('account/profile', [
            'mustVerifyEmail' => $status->for((int) $request->user()?->getAuthIdentifier())['mustVerifyEmail'],
            'status' => $request->session()->get('status'),
            'bio' => (string) ($request->user()->bio ?? ''),
            // Profile's "Set by the site": your author page, where it has an address.
            'authorPage' => $authorPath === null ? null : ['url' => rtrim(url('/'), '/').$authorPath, 'path' => $authorPath],
        ]);
    }

    public function update(Request $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $updateProfile->execute(new UpdateProfileInput(
            userId: $actorId,
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            actorId: $actorId,
            // '' clears it: the form always sends the field.
            bio: $request->string('bio')->toString(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
