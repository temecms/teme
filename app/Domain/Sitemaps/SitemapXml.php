<?php

/**
 * The sitemaps as XML (docs/studies/seo.md §3.7): the index of pages, or
 * one page's addresses with when each last changed and its images, each
 * opening with the stylesheet a browser shows them through. Every text is
 * escaped here, what XML 1.0 can't carry removed first, so no value
 * reaches a sitemap by another road.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps;

final class SitemapXml
{
    /** The sitemaps protocol's namespace. */
    public const string SITEMAPS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Google's image extension's namespace. */
    public const string IMAGES = 'http://www.google.com/schemas/sitemap-image/1.1';

    /**
     * XHTML's namespace, which an address's language versions are written in (I-3360).
     *
     * @since 1.0.0
     */
    public const string XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * The index: a `<sitemap>` for each page's address.
     *
     * @param  list<string>  $children
     */
    public static function index(array $children): string
    {
        $xml = self::opening().'<sitemapindex xmlns="'.self::SITEMAPS.'">'."\n";

        foreach ($children as $child) {
            $xml .= '<sitemap><loc>'.self::text($child).'</loc></sitemap>'."\n";
        }

        return $xml."</sitemapindex>\n";
    }

    /**
     * One page: a `<url>` for each address, its `<lastmod>` in W3C form in
     * UTC when known, an `<image:image>` for each image, and an
     * `<xhtml:link>` for each of its language versions.
     */
    public static function urlset(SitemapPage $page): string
    {
        $xml = self::opening().'<urlset xmlns="'.self::SITEMAPS.'" xmlns:image="'.self::IMAGES.'" xmlns:xhtml="'.self::XHTML.'">'."\n";

        foreach ($page->addresses as $address) {
            $xml .= '<url><loc>'.self::text($address->loc).'</loc>';

            if ($address->lastmod !== null) {
                $xml .= '<lastmod>'.$address->lastmod->utc()->format(DATE_W3C).'</lastmod>';
            }

            foreach ($address->images as $image) {
                $xml .= '<image:image><image:loc>'.self::text($image).'</image:loc></image:image>';
            }

            foreach ($address->alternates as $alternate) {
                $xml .= '<xhtml:link rel="alternate" hreflang="'.self::text($alternate['hreflang']).'" href="'.self::text($alternate['href']).'"/>';
            }

            $xml .= "</url>\n";
        }

        return $xml."</urlset>\n";
    }

    /**
     * The declaration, then the stylesheet named by its path, so a browser
     * takes it from the host the sitemap came from.
     */
    private static function opening(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<?xml-stylesheet type="text/xsl" href="'.SitemapStylesheet::PATH.'"?>'."\n";
    }

    /**
     * Text for an element: what XML 1.0 can't carry removed (every control
     * character but tab and the line breaks, U+FFFE, U+FFFF, and a byte
     * that isn't UTF-8, which becomes `?` first), then `&`, `<`, `>` and
     * the quotes escaped.
     */
    private static function text(string $text): string
    {
        $clean = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8'));

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
