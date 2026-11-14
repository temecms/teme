<?php

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Users\Operations\Input\RemovePictureInput;
use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\RemovePicture;
use App\Domain\Users\Operations\SetPicture;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Your picture on the Profile page: choosing an image from Media hands it
 * to `SetPicture`, and removing it to `RemovePicture`; it changes nothing
 * itself.
 */
final class PictureController extends Controller
{
    public function update(Request $request, SetPicture $setPicture): RedirectResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $setPicture->execute(new SetPictureInput($actorId, $request->string('media')->toString(), $actorId));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Picture changed.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, RemovePicture $removePicture): RedirectResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $removePicture->execute(new RemovePictureInput($actorId, $actorId));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Picture removed. Your initials show instead.')]);

        return to_route('profile.edit');
    }
}
