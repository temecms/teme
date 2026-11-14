<?php

declare(strict_types=1);

namespace App\Http\Admin\Media;

use App\Domain\Media\Operations\DownloadImage;
use App\Domain\Media\Operations\Input\DownloadImageInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's Import images (I-453): one image per request, from its
 * address into the media library through DownloadImage. The new item's ID
 * comes back, or the library's own item when it already has the file.
 */
final class ImportImageController extends Controller
{
    public function __invoke(Request $request, DownloadImage $download): JsonResponse
    {
        $result = $download->execute(new DownloadImageInput(
            $request->string('address')->toString(),
            (string) $request->user()?->getAuthIdentifier(),
            $request->string('uploaded_for')->toString() ?: null,
        ));

        return response()->json(['id' => $result['mediaId'], 'created' => $result['created']], $result['created'] ? 201 : 200);
    }
}
