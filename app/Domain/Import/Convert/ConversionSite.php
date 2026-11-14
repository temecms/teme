<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert;

/**
 * The old site's own addresses, so the converter can tell its files from
 * other sites': its home, and where WordPress keeps its uploads
 * (`wp_upload_dir()`'s `baseurl`, on the same host or another). Hosts are
 * compared without case and without a leading `www.`.
 */
final readonly class ConversionSite
{
    private string $host;

    private string $uploadsHost;

    private string $uploadsPath;

    /**
     * @param  string  $home  WordPress's `home`, as the companion's probe gives it
     * @param  string|null  $uploads  the uploads folder's address; by default {home}/wp-content/uploads
     * @param  list<string>  $shortcodes  the shortcode names registered on the old site at export (I-336)
     */
    public function __construct(public string $home, ?string $uploads = null, public array $shortcodes = [])
    {
        $uploads ??= rtrim($home, '/').'/wp-content/uploads';
        $this->host = self::bare((string) parse_url($home, PHP_URL_HOST));
        $this->uploadsHost = self::bare((string) parse_url($uploads, PHP_URL_HOST)) ?: $this->host;
        $path = trim(self::fileKey((string) parse_url($uploads, PHP_URL_PATH)), '/');
        $this->uploadsPath = $path === '' ? '/' : "/{$path}/";
    }

    /**
     * A file map key (I-338): the path percent-decoded, its ASCII letters
     * lower-cased (strtolower() touches no other since PHP 8.2). The one
     * place a key is made: the media phase calls it for every path it
     * records, and uploadsKey() gives the same for an address.
     */
    public static function fileKey(string $path): string
    {
        return strtolower(rawurldecode($path));
    }

    /**
     * The path below the uploads folder of an address on this site, as
     * fileKey() makes a key; null for another site's address, or one
     * outside the uploads folder.
     */
    public function uploadsKey(string $src): ?string
    {
        $path = $this->ownPath($src);

        foreach ($path === null ? [] : array_unique([$this->uploadsPath, '/wp-content/uploads/']) as $prefix) {
            if (strlen($path) > strlen($prefix) && str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Whether the address is this site's: a path on it, an address on its
     * home or uploads host, or Jetpack's copy of one.
     */
    public function isOwn(string $src): bool
    {
        return $this->ownPath($src) !== null;
    }

    /**
     * The address's host, lower-cased and without `www.`, or null for one
     * without a host.
     */
    public function hostOf(string $src): ?string
    {
        $host = parse_url(str_starts_with(trim($src), '//') ? 'https:'.trim($src) : trim($src), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? self::bare($host) : null;
    }

    /**
     * The address made absolute against the home: a path or `//host/…`; an
     * address with a scheme as it is; null for one relative to a page, or
     * none.
     */
    public function absolute(string $href): ?string
    {
        $href = trim($href);

        return match (true) {
            $href === '' => null,
            str_starts_with($href, '//') => 'https:'.$href,
            str_starts_with($href, '/') => rtrim($this->home, '/').$href,
            default => parse_url($href, PHP_URL_SCHEME) !== null ? $href : null,
        };
    }

    /**
     * A page key (I-1702): the path percent-decoded, lower-cased, without
     * its trailing slash, `/` for the home. The one place a key for
     * SourceTargets::page() is made.
     */
    public static function pageKey(string $path): string
    {
        return '/'.trim(mb_strtolower(rawurldecode($path)), '/');
    }

    /**
     * The path, query (without `?`) and place (with `#`) of an address on
     * the old site's home host, over http or https, with or without `www.`,
     * or of a path on it; null for any other address.
     *
     * @return array{path: string, query: string, place: string}|null
     */
    public function pageOf(string $href): ?array
    {
        $href = trim($href);

        if (preg_match('#^/(?![/\\\\])#', $href) !== 1 && preg_match('#^https?://#i', $href) !== 1) {
            return null;
        }

        $parts = parse_url($href);

        if (! is_array($parts) || isset($parts['user']) || (isset($parts['host']) && self::bare($parts['host']) !== $this->host)) {
            return null;
        }

        return [
            'path' => ($parts['path'] ?? '') === '' ? '/' : $parts['path'],
            'query' => $parts['query'] ?? '',
            'place' => isset($parts['fragment']) ? '#'.$parts['fragment'] : '',
        ];
    }

    /**
     * The home's own folder, without a trailing slash: '' for a site at its
     * host's root.
     */
    public function homePath(): string
    {
        return rtrim((string) parse_url($this->home, PHP_URL_PATH), '/');
    }

    /**
     * The path of an address on this site, as fileKey() makes a key, or null.
     */
    private function ownPath(string $src): ?string
    {
        $src = trim($src);

        if ($src === '' || str_starts_with(strtolower($src), 'data:')) {
            return null;
        }

        $parts = parse_url(str_starts_with($src, '//') ? 'https:'.$src : $src);

        if (! is_array($parts) || isset($parts['user'])) {
            return null;
        }

        $path = self::fileKey($parts['path'] ?? '');

        if (! isset($parts['host'])) {
            return isset($parts['scheme']) || ! str_starts_with($path, '/') ? null : $path;
        }

        $host = self::bare($parts['host']);

        // Jetpack's image CDN serves a copy at i0.wp.com/{the site's host}/{path}.
        if (preg_match('/^i[0-3]\.wp\.com$/', $host) === 1 && preg_match('#^/([^/]+)(/.+)$#', $path, $copy) === 1) {
            [$host, $path] = [self::bare($copy[1]), $copy[2]];
        }

        return in_array($host, [$this->host, $this->uploadsHost], true) ? $path : null;
    }

    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }
}
