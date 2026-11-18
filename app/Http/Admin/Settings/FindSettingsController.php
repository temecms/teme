<?php

/**
 * Find a setting asks here as someone types on a Settings screen. It
 * answers only with what the person may change; a blank query searches
 * nothing (I-440).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Settings\Queries\FindSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FindSettingsController extends Controller
{
    public function __invoke(Request $request, FindSettings $find): JsonResponse
    {
        // A hundred characters is longer than any setting's name.
        $query = mb_substr(trim($request->string('q')->toString()), 0, 100);
        $actor = $request->user();

        if ($query === '' || $actor === null) {
            return response()->json(['screens' => [], 'settings' => []]);
        }

        return response()->json($find->run($query, $actor));
    }
}
