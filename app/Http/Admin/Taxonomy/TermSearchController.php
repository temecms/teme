<?php

declare(strict_types=1);

namespace App\Http\Admin\Taxonomy;

use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Types\TaxonomyRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The term pickers' search, as JSON: anyone who writes content may look terms
 * up, not only those who manage them. Any registered taxonomy can be searched.
 */
final class TermSearchController extends Controller
{
    public function __invoke(Request $request, TermPicker $picker, TaxonomyRegistry $taxonomies): JsonResponse
    {
        $taxonomy = $request->string('taxonomy')->toString();
        abort_unless($taxonomies->has($taxonomy), 404);

        return response()->json($picker->search($taxonomy, $request->string('q')->toString()));
    }
}
