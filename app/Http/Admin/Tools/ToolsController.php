<?php

/**
 * The Tools hub (I-467): a card for each tool the person may open; 403 when
 * there is none.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Navigation\ToolsHub;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ToolsController extends Controller
{
    public function __invoke(Request $request, ToolsHub $hub): Response
    {
        $user = $request->user();
        $pages = $user === null ? [] : $hub->pagesFor($user);

        abort_if($pages === [], 403);

        // "tools", not a type's name: no type literal in app/ (tests/Architecture/NoTypeLiteralsTest.php).
        return Inertia::render('tools/index', ['tools' => $pages]);
    }
}
