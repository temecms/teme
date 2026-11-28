<?php

/**
 * Serves a plugin's declared stylesheet itself: finds the file a manifest declares
 * under contributes.styles, checks that it is a plain file inside the package with
 * no link on its path, refuses what Teme can't wrap safely, and gives the sheet
 * wrapped in the teme.plugins cascade layer, with a hash of its content as its version.
 *
 * @package Teme
 * @since   1.8.0
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
     * What Teme says about an enabled plugin's declared file: its plugin's Composer name, its
     * content hash, and either the wrapped sheet or the reason it is refused. Null when the
     * plugin is not enabled, doesn't declare the file, or its package is missing.
     *
     * @return array{name: string, hash: string|null, css: string|null, refused: string|null}|null
     */
    public function verdict(string $code, string $file): ?array
    {
        $name = $this->nameOf($code);

        if ($name === null || ! $this->declares($name, $file)) {
            return null;
        }

        try {
            $source = $this->source($name, $file);
        } catch (InvalidArgumentException $refused) {
            return ['name' => $name, 'hash' => null, 'css' => null, 'refused' => $refused->getMessage()];
        }

        if ($source === null) {
            return null;
        }

        $raw = (string) file_get_contents($source);
        $digest = hash('sha256', $raw);
        $judged = Cache::rememberForever('plugin-styles:'.$digest, fn (): array => self::judge($raw));

        return ['name' => $name, 'hash' => substr($digest, 0, 12), 'css' => $judged['css'], 'refused' => $judged['refused']];
    }

    /**
     * The hash of an enabled plugin's declared sheet when Teme serves it, or null when it is
     * refused or isn't one it declares.
     */
    public function hash(string $code, string $file): ?string
    {
        $verdict = $this->verdict($code, $file);

        return $verdict === null || $verdict['css'] === null ? null : $verdict['hash'];
    }

    /**
     * Wraps a sheet's text in the teme.plugins layer, after refusing what can't be wrapped: an import
     * rule, a namespace rule, and braces that don't balance. The text is first read as the browser reads
     * it: every line break becomes a line feed and a NUL becomes U+FFFD, so the text that is checked is the
     * text that is served. A charset rule stays inside the layer, where it is ignored.
     *
     * @throws InvalidArgumentException
     */
    public static function wrap(string $css): string
    {
        $css = preg_replace('/\r\n?|\f/', "\n", $css) ?? $css;
        $css = str_replace("\0", "\u{FFFD}", $css);

        self::check($css);

        return '@layer teme.plugins {'."\n".trim($css)."\n".'}'."\n";
    }

    /**
     * The verdict on a sheet's text, cached by its hash: its wrapped form, or the reason it is refused.
     *
     * @return array{css: string|null, refused: string|null}
     */
    private static function judge(string $raw): array
    {
        try {
            return ['css' => self::wrap($raw), 'refused' => null];
        } catch (InvalidArgumentException $refused) {
            return ['css' => null, 'refused' => $refused->getMessage()];
        }
    }

    /**
     * The name of the enabled plugin with this code, or null when none is enabled.
     */
    private function nameOf(string $code): ?string
    {
        $name = null;

        foreach ($this->records->enabled() as $record) {
            if ((string) $record->code === $code) {
                $name = (string) $record->name;
            }
        }

        return $name;
    }

    /**
     * Whether the plugin's manifest declares this file under contributes.styles.
     */
    private function declares(string $name, string $file): bool
    {
        $manifest = $this->registry->all()[$name]->manifest ?? null;

        return $manifest !== null && in_array($file, array_column(DeclaredStyles::entries($manifest->contributes()), 'file'), true);
    }

    /**
     * The real path of the declared file inside the plugin's package, or null when the package
     * isn't on disk.
     *
     * @throws InvalidArgumentException when the file is a link or sits outside the package
     */
    private function source(string $name, string $file): ?string
    {
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

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('A stylesheet must be a plain file inside the package.');
        }

        return $real;
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

            if ($character === '#') {
                // A hash token's name is never a url( — the browser reads #url as a name, not a function.
                [, $index] = self::identifierAt($css, $index + 1);

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
                // A hex escape takes its digits and one white space after them; any other escape takes one character.
                $index += preg_match('/\G[0-9a-fA-F]{1,6}[ \t\n]?/', $css, $escape, 0, $index + 1) === 1 ? strlen($escape[0]) : 1;
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
