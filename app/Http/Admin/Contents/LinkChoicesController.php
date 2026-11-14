<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\LinkChoices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's link box asks here for what a link can lead to by ID:
 * articles, terms, authors and files, by title or name. It reads only;
 * nothing is changed.
 */
final class LinkChoicesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(LinkChoices::search($request->string('q')->limit(200, '')->toString()));
    }
}
