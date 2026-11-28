<?php

/**
 * The HTTP side of uploading a site's uploads copy in pieces on Tools › Import:
 * each request is one Operation, and each answers in JSON the way the export
 * file's upload does.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\Operations\CancelUploadsCopy;
use App\Domain\Import\Operations\FinishUploadsCopy;
use App\Domain\Import\Operations\Input\ReceiveUploadsCopyPieceInput;
use App\Domain\Import\Operations\Input\StartUploadsCopyInput;
use App\Domain\Import\Operations\Input\UploadsCopyUploadInput;
use App\Domain\Import\Operations\ReceiveUploadsCopyPiece;
use App\Domain\Import\Operations\StartUploadsCopy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UploadsCopyController extends Controller
{
    public function start(Request $request, string $source, StartUploadsCopy $start): JsonResponse
    {
        $result = $start->execute(new StartUploadsCopyInput(
            $source,
            $request->string('name')->toString(),
            $request->integer('size'),
            self::actor($request),
        ));

        return response()->json([
            'fileId' => $result['uploadId'],
            'receivedBytes' => $result['receivedBytes'],
            'sizeBytes' => $result['sizeBytes'],
        ]);
    }

    public function piece(Request $request, string $upload, ReceiveUploadsCopyPiece $receive): JsonResponse
    {
        $result = $receive->execute(new ReceiveUploadsCopyPieceInput(
            $upload,
            $request->integer('offset', (int) $request->header('X-Upload-Offset', '0')),
            $request->getContent(),
            self::actor($request),
        ));

        return response()->json($result);
    }

    public function finish(Request $request, string $upload, FinishUploadsCopy $finish): JsonResponse
    {
        try {
            $result = $finish->execute(new UploadsCopyUploadInput($upload, self::actor($request)));
        } catch (ValidationException $refused) {
            // The uploader reads a refusal as a message, not a failed request.
            return response()->json(['refused' => true, 'message' => (string) (collect($refused->errors())->flatten()->first() ?? '')]);
        }

        return response()->json(['refused' => $result['refused'] !== null, 'message' => $result['refused'], 'finished' => $result['refused'] === null]);
    }

    public function cancel(Request $request, string $upload, CancelUploadsCopy $cancel): JsonResponse
    {
        $cancel->execute(new UploadsCopyUploadInput($upload, self::actor($request)));

        return response()->json(['cancelled' => true]);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
