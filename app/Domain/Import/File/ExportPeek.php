<?php

/**
 * The first 64 KB of an uploaded WordPress export: refuses a DOCTYPE and a
 * root that isn’t an rss channel with WordPress’s export namespace. The full
 * streaming read belongs to the reading job.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class ExportPeek
{
    public const int BYTES = 65_536;

    public const string REFUSED = 'That isn’t a WordPress export file. Teme reads the .xml file from WordPress’s Tools › Export. Choose that file and try again.';

    /**
     * Why the peek refuses the bytes, or null when they look like a WordPress export.
     */
    public static function refuse(string $head): ?string
    {
        if (preg_match('/<!DOCTYPE/i', $head) === 1) {
            return self::REFUSED;
        }

        // Strip the XML declaration and comments so the root element is next.
        $body = preg_replace('/^\\xEF\\xBB\\xBF/', '', $head) ?? $head;
        $body = preg_replace('/<\\?xml\\b[^?]*\\?>/i', '', $body, 1) ?? $body;
        $body = preg_replace('/<!--.*?-->/s', '', $body) ?? $body;
        $body = ltrim($body);

        if (! preg_match('/^<rss\\b/i', $body)) {
            return self::REFUSED;
        }

        if (preg_match('#xmlns:wp\\s*=\\s*["\']http://wordpress\\.org/export/1\\.[012]/["\']#i', $body) !== 1) {
            return self::REFUSED;
        }

        return null;
    }
}
