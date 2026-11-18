<?php

/**
 * What teme:theme:check refuses in a theme's views (I-3375): an inline
 * script, an on* attribute, or core's own inline-script component, each of
 * which stops working once the public site's content security policy is
 * enforced. A check against mistakes, not a security boundary: the policy
 * is the boundary, and a page never scans views.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ThemeScripts
{
    /** A script with no src attribute (data-src is not one) that isn't JSON-LD; its tag may span lines. */
    private const string INLINE = '~<script\b(?![^>]*\ssrc\s*=)(?![^>]*\stype\s*=\s*["\']?application/ld\+json)[^>]*>~i';

    /** An on* attribute inside a tag, on whichever line of the tag it stands. */
    private const string HANDLER = '~<[a-z][^>]*?\s(on[a-z]+\s*=)~i';

    /** Core's own component, which a theme never writes. */
    private const string CORE = '~<x-teme::inline-script\b~i';

    /**
     * One refusal for each inline script, on* attribute and use of core's
     * component in the theme's views, each read whole and named by the line
     * it stands on, in path and line order; [] when there is none, or the
     * theme has no views.
     *
     * @return list<string>
     */
    public static function refusals(string $themePath): array
    {
        $views = rtrim($themePath, '/').'/views';

        if (! is_dir($views)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        $refusals = [];

        foreach ($files as $path) {
            $name = substr($path, strlen($views) + 1);
            $text = (string) file_get_contents($path);
            $found = [];

            foreach ([self::INLINE => 0, self::HANDLER => 1, self::CORE => 2] as $pattern => $kind) {
                preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[$kind === 1 ? 1 : 0] as [, $offset]) {
                    $found[] = [substr_count($text, "\n", 0, $offset) + 1, $kind];
                }
            }

            sort($found);

            foreach ($found as [$line, $kind]) {
                $words = ['file' => $name, 'line' => $line];
                $refusals[] = match ($kind) {
                    0 => __('views/:file, line :line: an inline <script> stops working when the content security policy is enforced. Move it into the theme\'s build, as a module.', $words),
                    1 => __('views/:file, line :line: an on… attribute stops working when the content security policy is enforced. Add the handler in the theme\'s script instead.', $words),
                    default => __('views/:file, line :line: <x-teme::inline-script> is core\'s own. A theme\'s scripts go in its build.', $words),
                };
            }
        }

        return $refusals;
    }
}
