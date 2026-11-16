<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\CommentViews;
use App\Domain\Site\ItemLanguage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/_teme/comments/pending`: this browser's own comments on an item, for
 * core's script (I-276, 1.8b′'s D6), found by the key the browser holds,
 * sent in a header and never in the address, and by the item's address,
 * never its ID (1.7c's D11); each drawn by the theme as only its writer
 * sees it. No session, no cookie, and nothing keeps the answer.
 */
final class PendingCommentsController extends Controller
{
    /** The header the key comes in. */
    public const string HEADER = 'X-Teme-Commenter';

    public function __invoke(Request $request, CommentViews $views): JsonResponse
    {
        $path = $request->query('path');
        $key = $request->headers->get(self::HEADER);

        if (is_string($path)) {
            // Drawn in the language of the page that asks (I-3360).
            ItemLanguage::useAddress($path);
        }

        $own = is_string($path) && $key !== null ? $views->own($path, $key) : [];

        return new JsonResponse(['comments' => array_map(CommentFragments::place(...), $own)], 200, ['Cache-Control' => 'no-store, private']);
    }
}
