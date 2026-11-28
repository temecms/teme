<?php

/**
 * Answers a picker's search for posts and pages, for the person asking: the
 * items they may open whose title holds what was typed. Reads only.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\ItemChoices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemChoicesController extends Controller
{
    /**
     * The shown items and the total for the words typed, as the person sees them.
     *
     * @since next
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(ItemChoices::search($request->string('q')->limit(200, '')->toString(), (string) $request->user()?->getAuthIdentifier()));
    }
}
