<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\LinkedContent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's checks ask here whether the articles a link was just added to
 * are published, for the warning about links to unpublished articles. It
 * reads only, and shows anyone who may write nothing the link picker and the
 * lists do not already show them.
 */
final class LinkedContentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{ids: list<string>} $valid */
        $valid = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['string', 'size:26'],
        ]);

        return response()->json((object) LinkedContent::states($valid['ids']));
    }
}
