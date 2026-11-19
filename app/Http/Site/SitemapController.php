<?php

/**
 * The sitemaps' addresses (I-296): the index, every page a child has, the
 * stylesheet, and WordPress's and Yoast's index addresses, sent to the
 * index. While the site discourages search engines, and for a page that
 * isn't one, PublicController answers with the site's own 404 page. Marked
 * cacheable by its routes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\SiteLanguage;
use App\Domain\Sitemaps\Sitemaps;
use App\Domain\Sitemaps\SitemapStylesheet;
use App\Domain\Sitemaps\SitemapXml;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

final class SitemapController extends Controller
{
    /** Every sitemap's headers: XML, and kept out of search results itself. */
    private const array XML = ['Content-Type' => 'application/xml; charset=UTF-8', 'X-Robots-Tag' => 'noindex, follow'];

    public function __construct(
        private readonly Sitemaps $sitemaps,
        private readonly SiteLanguage $language,
        private readonly PublicController $public,
    ) {}

    public function index(Request $request): BaseResponse
    {
        return Sitemaps::on()
            ? new Response(SitemapXml::index($this->sitemaps->index()), 200, self::XML)
            : $this->public->handle($request);
    }

    public function child(Request $request, string $file): BaseResponse
    {
        $page = Sitemaps::on() ? $this->sitemaps->page($file) : null;

        return $page === null ? $this->public->handle($request) : new Response(SitemapXml::urlset($page), 200, self::XML);
    }

    public function stylesheet(Request $request): BaseResponse
    {
        return Sitemaps::on()
            ? new Response(SitemapStylesheet::xsl($this->language->tag()), 200, ['Content-Type' => 'text/xsl; charset=UTF-8', 'X-Robots-Tag' => 'noindex, follow'])
            : $this->public->handle($request);
    }

    public function moved(Request $request): BaseResponse
    {
        return Sitemaps::on()
            ? new RedirectResponse(rtrim(url('/'), '/').'/sitemap.xml', 301)
            : $this->public->handle($request);
    }
}
