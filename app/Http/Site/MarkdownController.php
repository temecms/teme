<?php

/**
 * The plain-text copy of an item at its address with `.md` added (I-713),
 * as Markdown in UTF-8, kept out of search results itself, with the page
 * as its canonical address. Anything else ending `.md`, and every address
 * while the switch is off or the site discourages search engines (as for
 * llms.txt), goes back to PublicController unchanged, so redirects and the
 * 404 log still see it. Marked cacheable by its route.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Api\Delivery\DeliverySwitches;
use App\Domain\Api\Llms\MarkdownPages;
use App\Domain\Seo\WholeSite;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

final class MarkdownController extends Controller
{
    public function __construct(private readonly PublicController $public) {}

    public function __invoke(Request $request, string $path): BaseResponse
    {
        $page = DeliverySwitches::llmsOn() && ! WholeSite::discouraged() ? MarkdownPages::find($path) : null;

        if ($page === null) {
            return $this->public->handle($request);
        }

        return new Response(MarkdownPages::text($page), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Link' => '<'.$page['url'].'>; rel="canonical"',
        ]);
    }
}
