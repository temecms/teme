<?php

/**
 * The tree of a nesting type's items, such as the Pages tree: drawn from
 * TreeRows and marked Soon, since moving items in it is not built yet.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\TreeRows;
use App\Domain\Types\ContentTypeRegistry;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class ContentTreeController extends Controller
{
    /**
     * @since next
     */
    public function __invoke(string $type, ContentTypeRegistry $types): Response
    {
        $contentType = $types->bySegment($type) ?? abort(404);
        abort_unless($contentType->hierarchical, 404);

        return Inertia::render('content/tree', [
            'type' => $type,
            'labels' => ['singular' => $contentType->singular, 'plural' => $contentType->plural],
            ...TreeRows::of($contentType->key),
            'most' => TreeRows::MOST,
        ]);
    }
}
