<?php

/**
 * `GET /_teme/suggest?q=…&lang=…`: a few matching items as JSON, for a
 * theme's search overlay. It reads only, through Suggestions.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Search\Suggestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SuggestController extends Controller
{
    public function __invoke(Request $request, Suggestions $suggestions): JsonResponse
    {
        $query = $request->query('q');
        $tag = $request->query('lang');
        $query = is_string($query) ? $query : '';

        // One answer in a given language, never one that depends on who asks.
        return new JsonResponse(
            ['query' => $query, 'items' => $suggestions->for($query, is_string($tag) && $tag !== '' ? $tag : null)],
            200,
            ['X-Robots-Tag' => 'noindex'],
        );
    }
}
