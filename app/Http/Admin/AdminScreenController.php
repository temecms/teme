<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use App\Http\Admin\Contents\ContentActionController;
use App\Http\Admin\Contents\ContentListController;
use App\Http\Admin\Taxonomy\TermController;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * The screens at /admin/{segment}: a taxonomy's terms, or a type's items.
 * Which one is found from the segment on each request, so a taxonomy or a
 * type made in the admin answers at once (I-3296); the request goes to that
 * screen's own controller once that screen's own permission allows it.
 */
final class AdminScreenController extends Controller
{
    public function index(string $segment): mixed
    {
        return $this->isTaxonomy($segment)
            ? app()->call([app(TermController::class), 'index'], ['taxonomy' => $segment])
            : app()->call([app(ContentListController::class), 'index'], ['type' => $segment]);
    }

    public function store(string $segment): mixed
    {
        return $this->isTaxonomy($segment)
            ? app()->call([app(TermController::class), 'store'], ['taxonomy' => $segment])
            : app()->call([app(ContentListController::class), 'store'], ['type' => $segment]);
    }

    public function destroy(string $segment, string $id): mixed
    {
        return $this->isTaxonomy($segment)
            ? app()->call([app(TermController::class), 'destroy'], ['taxonomy' => $segment, 'term' => $id])
            : app()->call([app(ContentActionController::class), 'destroy'], ['type' => $segment, 'content' => $id]);
    }

    /**
     * Whether a taxonomy has this segment, once the person may open its
     * screen; otherwise once they may open the type's list at it, which
     * answers 404 for a segment nothing has.
     */
    private function isTaxonomy(string $segment): bool
    {
        $taxonomy = app(TaxonomyRegistry::class)->bySegment($segment);

        if ($taxonomy !== null) {
            Gate::authorize($taxonomy->ability);

            return true;
        }

        Gate::authorize(TypeAccess::OPEN_LIST, [$segment]);

        return false;
    }
}
