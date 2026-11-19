<?php

/**
 * The editor's structured-data check: what the gate leaves out of an item's
 * page as one revision makes it (I-703), for whoever may edit the item. The
 * query decides who may ask.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Seo\Queries\EditorStructuredData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StructuredDataController extends Controller
{
    public function __invoke(Request $request, string $content, EditorStructuredData $structuredData): JsonResponse
    {
        $revision = $request->query('revision');

        abort_unless(is_string($revision) && $revision !== '', 404);

        $withheld = $structuredData->for($content, $request->user(), $revision);

        abort_if($withheld === null, 403);

        return response()->json(['withheld' => $withheld]);
    }
}
