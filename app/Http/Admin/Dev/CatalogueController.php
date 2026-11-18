<?php

/**
 * The design system's catalogue: every shared component in its states, for
 * looking at and for the browser tests. It exists only on a local site and
 * under test (docs/architecture/design-system.md, section 9.3).
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogueController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return Inertia::render('dev/catalogue');
    }
}
