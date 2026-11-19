<?php

/**
 * /llms.txt (I-713): the guide to the site's published content for AI
 * assistants, as plain text in UTF-8, kept out of search results itself.
 * While its switch is off, or the site discourages search engines, the
 * site's own 404 page answers. Marked cacheable by its route.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Api\Llms\LlmsTxt;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class LlmsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $text = LlmsTxt::text();

        if ($text === null) {
            abort(404);
        }

        return new Response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
