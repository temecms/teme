<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Operations\ConfirmRestoredSite;
use App\Domain\Backups\Operations\Input\ConfirmRestoredSiteInput;
use App\Domain\Backups\RestoredSiteAnswer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A person's answer, on Tools › Backups, to the pause a restore leaves
 * (I-363): this server is now the site, or this is a copy.
 */
final class RestoredSiteController extends Controller
{
    public function __invoke(Request $request, ConfirmRestoredSite $confirm): RedirectResponse
    {
        $answer = RestoredSiteAnswer::tryFrom((string) $request->input('answer'))
            ?? throw ValidationException::withMessages(['answer' => __('Answer site or copy.')]);

        try {
            $names = $confirm->execute(new ConfirmRestoredSiteInput($answer, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('tools.backups.index');
        }

        $places = BackupWords::names($names);

        Inertia::flash('toast', ['type' => 'success', 'message' => $answer === RestoredSiteAnswer::ThisIsTheSite
            ? __('Backups resume at :places.', ['places' => $places])
            : __('Removed :places from this site’s backup places. Their backups stay where they are.', ['places' => $places])]);

        return to_route('tools.backups.index');
    }
}
