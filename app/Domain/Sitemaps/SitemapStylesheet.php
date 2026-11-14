<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

/**
 * The stylesheet a browser shows a sitemap through (I-868): the index as a
 * table of its lists, a page as a table of its addresses, when each last
 * changed and how many images it names, light or dark as the reader's
 * system is. Search engines read the XML and never this.
 */
final class SitemapStylesheet
{
    /** Its address, from the site's root. */
    public const string PATH = '/sitemap.xsl';

    private const string XSL = <<<'XSL'
        <?xml version="1.0" encoding="UTF-8"?>
        <xsl:stylesheet version="1.0"
            xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
            xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
            xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
            exclude-result-prefixes="s image">
            <xsl:output method="html" encoding="UTF-8" indent="yes"/>
            <xsl:template match="/">
                <html lang="{lang}">
                    <head>
                        <meta name="viewport" content="width=device-width, initial-scale=1"/>
                        <meta name="robots" content="noindex"/>
                        <title>Sitemap</title>
                        <style>
                            :root { color-scheme: light dark; --text: #1f2328; --muted: #59636e; --line: #d1d9e0; --band: #f6f8fa; --link: #0b57d0; --page: #ffffff; }
                            @media (prefers-color-scheme: dark) { :root { --text: #e6edf3; --muted: #9198a1; --line: #3d444d; --band: #151b23; --link: #8ab4f8; --page: #0d1117; } }
                            body { margin: 0; background: var(--page); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
                            main { max-width: 64rem; margin: 0 auto; padding: 2rem 1rem; }
                            h1 { font-size: 1.5rem; line-height: 1.25; margin: 0 0 0.5rem; }
                            p { margin: 0 0 1rem; color: var(--muted); }
                            a { color: var(--link); }
                            table { width: 100%; border-collapse: collapse; margin: 0 0 1rem; }
                            th, td { text-align: start; vertical-align: top; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--line); }
                            tbody tr:nth-child(odd) td { background: var(--band); }
                            td.address { overflow-wrap: anywhere; }
                            th.number, td.number { text-align: end; white-space: nowrap; }
                        </style>
                    </head>
                    <body>
                        <main>
                            <h1>Sitemap</h1>
                            <xsl:apply-templates select="s:sitemapindex | s:urlset"/>
                            <p>Search engines read this file as XML. This table shows the same list for people.</p>
                        </main>
                    </body>
                </html>
            </xsl:template>
            <xsl:template match="s:sitemapindex">
                <p>The lists of this site’s addresses that search engines are asked to read. Each list holds up to 2,000 addresses.</p>
                <p><xsl:value-of select="count(s:sitemap)"/><xsl:choose><xsl:when test="count(s:sitemap) = 1"><xsl:text> list</xsl:text></xsl:when><xsl:otherwise><xsl:text> lists</xsl:text></xsl:otherwise></xsl:choose></p>
                <table>
                    <thead><tr><th scope="col">List</th></tr></thead>
                    <tbody>
                        <xsl:for-each select="s:sitemap">
                            <tr><td class="address"><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td></tr>
                        </xsl:for-each>
                    </tbody>
                </table>
            </xsl:template>
            <xsl:template match="s:urlset">
                <p><a href="/sitemap.xml">All lists</a></p>
                <p>These addresses are on this site, and search engines are asked to list them.</p>
                <p><xsl:value-of select="count(s:url)"/><xsl:choose><xsl:when test="count(s:url) = 1"><xsl:text> address</xsl:text></xsl:when><xsl:otherwise><xsl:text> addresses</xsl:text></xsl:otherwise></xsl:choose></p>
                <table>
                    <thead><tr><th scope="col">Address</th><th scope="col">Last changed (UTC)</th><th scope="col" class="number">Images</th></tr></thead>
                    <tbody>
                        <xsl:for-each select="s:url">
                            <tr>
                                <td class="address"><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                                <td><xsl:choose><xsl:when test="s:lastmod"><xsl:value-of select="concat(substring(s:lastmod, 1, 10), ' ', substring(s:lastmod, 12, 5))"/></xsl:when><xsl:otherwise>—</xsl:otherwise></xsl:choose></td>
                                <td class="number"><xsl:value-of select="count(image:image)"/></td>
                            </tr>
                        </xsl:for-each>
                    </tbody>
                </table>
            </xsl:template>
        </xsl:stylesheet>
        XSL;

    /**
     * The XSL, the page it makes in the site's language.
     */
    public static function xsl(string $language): string
    {
        return str_replace('{lang}', htmlspecialchars($language, ENT_XML1 | ENT_QUOTES, 'UTF-8'), self::XSL);
    }
}
