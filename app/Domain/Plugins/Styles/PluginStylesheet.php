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
        self::check($css);

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
     * Reads the sheet once, left to right, as the CSS tokenizer does: comments, strings, escapes
     * and unquoted url() are skipped as the text they are, so a brace or an at-rule hidden in
     * one of them can't change the reading. Refuses an @import or @namespace, and braces that
     * don't balance in order.
     *
     * @throws InvalidArgumentException
     */
    private static function check(string $css): void
    {
        $depth = 0;
        $length = strlen($css);
        $index = 0;

        while ($index < $length) {
            $character = $css[$index];

            if ($character === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;

                continue;
            }

            if ($character === '"' || $character === "'") {
                $index = self::endOfString($css, $index);

                continue;
            }

            if ($character === '@') {
                [$name, $index] = self::identifierAt($css, $index + 1);

                if (in_array($name, ['import', 'namespace'], true)) {
                    throw new InvalidArgumentException('A stylesheet may not import another or declare a namespace.');
                }

                continue;
            }

            if ($character === '\\' || self::isNameCharacter($character)) {
                [$name, $end] = self::identifierAt($css, $index);

                if ($end === $index) {
                    $index++;

                    continue;
                }

                $index = $name === 'url' && ($css[$end] ?? '') === '(' ? self::endOfUnquotedUrl($css, $end + 1) : $end;

                continue;
            }

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;

                if ($depth < 0) {
                    throw new InvalidArgumentException('A stylesheet’s braces do not balance.');
                }
            }

            $index++;
        }

        if ($depth !== 0) {
            throw new InvalidArgumentException('A stylesheet’s braces do not balance.');
        }
    }

    /**
     * The index just past a string that starts at the given quote, which ends at its
     * matching quote, at a newline, or at the end of the sheet.
     */
    private static function endOfString(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);

        for ($index = $start + 1; $index < $length; $index++) {
            if ($css[$index] === '\\') {
                $index++;
            } elseif ($css[$index] === $quote) {
                return $index + 1;
            } elseif ($css[$index] === "\n") {
                return $index;
            }
        }

        return $length;
    }

    /**
     * The index just past the ')' that closes an unquoted url( whose contents start at the given index,
     * or the start of a quoted url, which is then read as a string.
     */
    private static function endOfUnquotedUrl(string $css, int $start): int
    {
        $index = $start;

        while ($index < strlen($css) && in_array($css[$index], [' ', "\t", "\n", "\r", "\f"], true)) {
            $index++;
        }

        if (($css[$index] ?? '') === '"' || ($css[$index] ?? '') === "'") {
            return $index;
        }

        while ($index < strlen($css) && $css[$index] !== ')') {
            $index += $css[$index] === '\\' ? 2 : 1;
        }

        return $index + 1;
    }

    /**
     * The name that starts at the given index, with its escapes decoded and lower-cased, and the
     * index just past it. A hex escape takes up to six digits and one following space, as CSS does.
     *
     * @return array{0: string, 1: int}
     */
    private static function identifierAt(string $css, int $start): array
    {
        $name = '';
        $index = $start;
        $length = strlen($css);

        while ($index < $length) {
            $character = $css[$index];

            if ($character === '\\') {
                if ($index + 1 >= $length || $css[$index + 1] === "\n") {
                    break;
                }

                if (preg_match('/\G([0-9a-fA-F]{1,6})([ \t\n]|\r\n)?/', $css, $hex, 0, $index + 1) === 1) {
                    $code = hexdec($hex[1]);
                    $valid = $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF);
                    $name .= mb_chr($valid ? $code : 0xFFFD, 'UTF-8');
                    $index += 1 + strlen($hex[0]);
                } else {
                    $name .= $css[$index + 1];
                    $index += 2;
                }

                continue;
            }

            if (! self::isNameCharacter($character)) {
                break;
            }

            $name .= $character;
            $index++;
        }

        return [strtolower($name), $index];
    }

    /**
     * Whether a character can be part of a CSS name, without an escape.
     */
    private static function isNameCharacter(string $character): bool
    {
        return preg_match('/[A-Za-z0-9_\-]/', $character) === 1 || ord($character) >= 0x80;
    }
}
