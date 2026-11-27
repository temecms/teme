<?php

/**
 * `GET /_teme/region?name=…&lang=…`: the HTML of a region that waits for a
 * viewport width, for core's regions script to put in its placeholder. It
 * reads only: the same drawing as the region in a page, in the language asked.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Regions\RegionDraw;
use App\Domain\Themes\ActiveTheme;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RegionFragmentController extends Controller
{
    public function __invoke(Request $request, ActiveTheme $active, RegionDeclarations $declarations, RegionDraw $draw, SiteLanguages $languages, PageLanguage $pageLanguage): Response
    {
        PublicPage::start($request);

        $name = $request->query('name');
        $tag = $request->query('lang');
        $theme = $active->get();
        $declaration = $theme === null || ! is_string($name) ? null : ($declarations->for($theme)[$name] ?? null);

        // Only a region that waits for a width has a fragment; any other is in its page.
        if ($theme === null || $declaration === null || $declaration['minWidth'] === null) {
            return new Response('', 404, ['X-Robots-Tag' => 'noindex']);
        }

        // A language that isn't a shown one of the site is read as the default's, as search suggestions read it.
        $shown = is_string($tag) && $tag !== $languages->defaultTag() && $languages->has($tag) && $languages->isShown($tag) ? $tag : $languages->defaultTag();
        $pageLanguage->use($shown);
        $html = trim($draw->html($theme, (string) $name, $declaration));

        return new Response($html, $html === '' ? 404 : 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
