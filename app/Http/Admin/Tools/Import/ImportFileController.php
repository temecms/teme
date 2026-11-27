<?php

/**
 * Uploading a WordPress export file in pieces: start, receive, finish, cancel,
 * delete, and the file’s status for the upload form.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\Operations\CancelImportUpload;
use App\Domain\Import\Operations\DeleteImportFile;
use App\Domain\Import\Operations\FinishImportUpload;
use App\Domain\Import\Operations\Input\CancelImportUploadInput;
use App\Domain\Import\Operations\Input\DeleteImportFileInput;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Import\Operations\ReceiveImportPiece;
use App\Domain\Import\Operations\StartImportUpload;
use App\Domain\Import\Queries\ImportFiles;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ImportFileController extends Controller
{
    public function start(Request $request, StartImportUpload $start): JsonResponse
    {
        $result = $start->execute(new StartImportUploadInput(
            $request->string('name')->toString(),
            $request->integer('size'),
            self::actor($request),
            $request->filled('reader') ? $request->string('reader')->toString() : null,
        ));

        return response()->json($result);
    }

    public function piece(Request $request, string $file, ReceiveImportPiece $receive): JsonResponse
    {
        $result = $receive->execute(new ReceiveImportPieceInput(
            $file,
            $request->integer('offset', (int) $request->header('X-Upload-Offset', '0')),
            $request->getContent(),
            self::actor($request),
        ));

        return response()->json($result);
    }

    public function finish(Request $request, string $file, FinishImportUpload $finish): JsonResponse
    {
        $result = $finish->execute(new FinishImportUploadInput($file, self::actor($request)));

        return response()->json([
            'fileId' => $result['fileId'],
            'refused' => $result['refused'],
            'message' => $result['refused']
                ? (app(ImportFiles::class)->status($result['fileId'])['refused'] ?? null)
                : null,
        ]);
    }

    public function cancel(Request $request, string $file, CancelImportUpload $cancel): JsonResponse
    {
        $cancel->execute(new CancelImportUploadInput($file, self::actor($request)));

        return response()->json(['cancelled' => true]);
    }

    public function destroy(Request $request, string $file, DeleteImportFile $delete): JsonResponse|RedirectResponse
    {
        $delete->execute(new DeleteImportFileInput($file, self::actor($request)));

        // The report's Delete the file… goes back to it, saying so.
        if ($request->header('X-Inertia') !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Deleted the export file. What was imported stays.']);

            return redirect()->back(fallback: route('tools.importer.index'));
        }

        return response()->json(['deleted' => true]);
    }

    public function show(string $file, ImportFiles $files): JsonResponse
    {
        $status = $files->status($file);
        abort_if($status === null, 404);

        return response()->json($status);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
