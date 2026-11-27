<?php

/**
 * Serves a plugin's declared stylesheet itself: finds the file a manifest declares
 * under contributes.styles, checks that it is a plain file inside the package with
 * no link on its path, refuses what Teme can't wrap safely, and gives the sheet
 * wrapped in the teme.plugins cascade layer, with a hash of its content as its version.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Styles;

use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class PluginStylesheet
{
    public function __construct(
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * The wrapped sheet and its content hash for an enabled plugin's declared file, or
     * a reason it isn't served: an InvalidArgumentException naming why, or null when the
     * plugin or the file isn't one it declares.
     *
     * @return array{css: string, hash: string}|null
     *
     * @throws InvalidArgumentException when a declared file can't be served safely
     */
    public function serve(string $code, string $file): ?array
    {
        $found = $this->found($code, $file);

        if ($found === null) {
            return null;
        }

        [$package, $source] = $found;
        $raw = (string) file_get_contents($source);
        $hash = substr(hash('sha256', $raw), 0, 12);

        return ['css' => Cache::rememberForever('plugin-styles:'.$hash, fn (): string => self::wrap($raw)), 'hash' => $hash];
    }

    /**
     * The hash of an enabled plugin's declared sheet, or null when it isn't served.
     */
    public function hash(string $code, string $file): ?string
    {
        $found = $this->found($code, $file);

        return $found === null ? null : substr(hash('sha256', (string) file_get_contents($found[1])), 0, 12);
    }

    /**
     * Wraps a sheet's text in the teme.plugins layer, moving a leading @charset to the top,
     * after refusing what can't be wrapped: @import, @namespace, and braces that don't balance.
     *
     * @throws InvalidArgumentException
     */
    public static function wrap(string $css): string
    {
        $stripped = self::outsideCommentsAndStrings($css);

        if (preg_match('/@(import|namespace)\b/i', $stripped) === 1) {
            throw new InvalidArgumentException('A stylesheet may not import another or declare a namespace.');
        }

        if (! self::balanced($stripped)) {
            throw new InvalidArgumentException('A stylesheet’s braces do not balance.');
        }

        $charset = '';

        if (preg_match('/@charset\s+"[^"]*";/i', $css, $found) === 1) {
            $charset = $found[0]."\n";
        }

        $body = preg_replace('/@charset\s+"[^"]*";/i', '', $css) ?? $css;

        return $charset.'@layer teme.plugins {'."\n".trim($body)."\n".'}'."\n";
    }

    /**
     * The package's declared file as the plugin's code and file name it, with its real path
     * and its package's, or null when the plugin is not enabled or doesn't declare the file.
     *
     * @return array{0: string, 1: string}|null
     *
     * @throws InvalidArgumentException when the file is a link or sits outside the package
     */
    private function found(string $code, string $file): ?array
    {
        $name = null;

        foreach ($this->records->enabled() as $record) {
            if ((string) $record->code === $code) {
                $name = (string) $record->name;
            }
        }

        $manifest = $name === null ? null : ($this->registry->all()[$name]->manifest ?? null);

        if ($manifest === null || ! in_array($file, array_column(DeclaredStyles::entries($manifest->contributes()), 'file'), true)) {
            return null;
        }

        $path = $this->registry->all()[$name]->path;
        $root = realpath($path);

        if ($root === false) {
            return null;
        }

        $walk = $path;

        foreach (explode('/', $file) as $part) {
            $walk .= '/'.$part;

            if (is_link($walk)) {
                throw new InvalidArgumentException('A stylesheet may not be a link.');
            }
        }

        $real = realpath($path.'/'.$file);

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.'/')) {
            throw new InvalidArgumentException('A stylesheet must be a plain file inside the package.');
        }

        return [$root, $real];
    }

    /**
     * The sheet with its comments and its strings removed, so its braces and its at-rules are read as the CSS says.
     */
    private static function outsideCommentsAndStrings(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        return preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $css) ?? $css;
    }

    /**
     * Whether every opening brace has its closing one, in order.
     */
    private static function balanced(string $css): bool
    {
        $depth = 0;

        foreach (str_split($css) as $character) {
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;

                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }
}
