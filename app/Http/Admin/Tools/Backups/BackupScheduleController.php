<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Operations\ChangeBackupSchedule;
use App\Domain\Backups\Operations\Input\ChangeBackupScheduleInput;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Changes when the nightly backup starts and what is kept, from Tools ›
 * Backups (I-361), through ChangeBackupSchedule.
 */
final class BackupScheduleController extends Controller
{
    public function __invoke(Request $request, ChangeBackupSchedule $change, BackupWords $words): RedirectResponse
    {
        try {
            $change->execute(new ChangeBackupScheduleInput(
                (array) $request->input('values', []),
                $request->integer('expected_version'),
                (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. The next backup starts :next.', ['next' => $words->next(CarbonImmutable::now())])]);

        return to_route('tools.backups.index');
    }
}
