<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Cards\CardMedia;
use App\Domain\Media\Queries\MediaCredits;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's card views and its featured image ask here for the media they
 * have just been given, in the shape the server's renderer uses, with each
 * image's credit. It reads only; anyone who may
 * write content may ask, which media.show does not allow a contributor.
 */
final class CardMediaController extends Controller
{
    public function __invoke(Request $request, CardMedia $media, MediaCredits $credits): JsonResponse
    {
        /** @var array{ids: list<string>} $valid */
        $valid = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['string', 'size:26'],
        ]);

        return response()->json((object) $credits->onto($media->forIds($valid['ids'])));
    }
}
