<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Search\Queries\AdminSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The command palette asks here as someone types (I-103). An empty query
 * answers every group empty and runs no search: going somewhere and running
 * a command need nothing from the server.
 */
final class SearchController extends Controller
{
    private const array EMPTY = ['content' => [], 'media' => [], 'terms' => [], 'users' => [], 'settings' => [], 'textSearch' => null];

    public function __invoke(Request $request, AdminSearch $search): JsonResponse
    {
        $query = trim($request->string('q')->toString());
        $actor = $request->user();

        if ($query === '' || $actor === null) {
            return response()->json(self::EMPTY);
        }

        return response()->json($search->run($query, $actor));
    }
}
