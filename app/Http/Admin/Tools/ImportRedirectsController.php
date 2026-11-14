<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Redirects\Operations\ImportRedirects;
use App\Domain\Redirects\Operations\Input\ImportRedirectsInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Imports a CSV file of redirects through ImportRedirects (I-872): a file
 * that imports whole says so in a toast; one with lines left out leaves
 * its report for the list to show once. It changes nothing itself.
 */
final class ImportRedirectsController extends Controller
{
    /** The largest file taken: 5,000 lines of redirects fit with room to spare. */
    private const int MOST_BYTES = 2 * 1024 * 1024;

    public function __invoke(Request $request, ImportRedirects $import): RedirectResponse
    {
        $file = $request->file('file');

        if ($file instanceof UploadedFile && (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file->getSize() > self::MOST_BYTES)) {
            throw ValidationException::withMessages(['file' => [__('Choose a file of 2 MB or less, or split it into parts.')]]);
        }

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => [__('Choose a CSV file to import.')]]);
        }

        /** @var array{added: int, refused: list<array{line: int, reason: string}>} $result */
        $result = $import->execute(new ImportRedirectsInput((string) $file->get(), (string) $request->user()?->getAuthIdentifier()));

        if ($result['refused'] === []) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('1 redirect imported, marked Imported.|:count redirects imported, marked Imported.', $result['added'])]);
        } else {
            $request->session()->flash(RedirectScreenController::IMPORTED, $result);
        }

        return to_route('tools.redirects.index');
    }
}
