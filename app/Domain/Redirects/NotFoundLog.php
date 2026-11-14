<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

/**
 * What the 404 log keeps (I-298, I-874): how many addresses, which it never
 * keeps, the probes bots send to every site, and what it keeps of where a
 * reader came from, the host alone. Its constants are the defaults of
 * Settings › Search and social's 404 log section, which the minute's run reads.
 */
final class NotFoundLog
{
    /** The most addresses the log keeps by default, the least recently seen making room (I-874). */
    public const int KEEP = 10_000;

    /**
     * Paths, in the path table's form, not logged by default: PHP files and anything
     * under a name starting with a dot, which bots probe on every site
     * (docs/studies/seo.md, §3.9). Matched by fnmatch(), where * also matches /.
     *
     * @var list<string>
     */
    public const array IGNORED = ['*.php', '.*', '*/.*'];

    /** The longest path kept, in characters, as the longest redirect source. */
    public const int LONGEST = RedirectSource::LONGEST;

    /**
     * Whether no row of the log could keep this path, in the path table's
     * form: text that isn't UTF-8, holds a control character, or is too long.
     * What a reader's request asks, reading nothing but the path.
     */
    public static function unkept(string $path): bool
    {
        return ! RedirectSource::readable($path) || mb_strlen($path) > self::LONGEST;
    }

    /**
     * Whether the log leaves this path out, in the path table's form: one no
     * row could keep, or one matching a pattern of the list by fnmatch(),
     * where * also matches /. The minute's run passes the site's list
     * (I-874); IGNORED is its default.
     *
     * @param  list<string>  $patterns
     */
    public static function ignored(string $path, array $patterns = self::IGNORED): bool
    {
        if (self::unkept($path)) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host a reader came from, lower-cased, when the Referer header
     * names one; never its path or query, which may say who they are.
     */
    public static function referrerHost(?string $referer): ?string
    {
        $host = $referer === null ? null : parse_url($referer, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = strtolower($host);

        return strlen($host) <= 253 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) === 1 ? $host : null;
    }
}
