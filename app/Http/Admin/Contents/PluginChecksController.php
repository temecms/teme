<?php

/**
 * The plugins' checks on one revision of an item, for the editor's
 * Checks (docs/guides/developers/plugins/seo.md), for whoever may edit the
 * item. Read only; the query decides who may ask.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Plugins\Seo\PluginChecks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PluginChecksController extends Controller
{
    public function __invoke(Request $request, string $content, PluginChecks $checks): JsonResponse
    {
        $revision = $request->query('revision');

        abort_unless(is_string($revision) && $revision !== '', 404);

        $groups = $checks->for($content, $request->user(), $revision);

        abort_if($groups === null, 403);

        return response()->json(['plugins' => $groups]);
    }
}
