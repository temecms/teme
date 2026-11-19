<?php

/**
 * Back up now on Tools › Backups (I-361): puts a backup in line through
 * RequestBackup and says when it starts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class BackUpNowController extends Controller
{
    public function __invoke(Request $request, RequestBackup $backup): RedirectResponse
    {
        try {
            $backup->execute(new RequestBackupInput((string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('tools.backups.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup requested. It starts within a minute.')]);

        return to_route('tools.backups.index');
    }
}
