<?php

/**
 * The editor's link popover asks here for articles to link to, by title. It
 * reads only; nothing is changed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\LinkTargets;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LinkTargetsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(LinkTargets::search($request->string('q')->limit(200, '')->toString()));
    }
}
