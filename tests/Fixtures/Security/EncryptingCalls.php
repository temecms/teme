<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use Symfony\Component\Finder\Finder;

/**
 * Finds every call that encrypts, or adds casts at run time, in a folder's
 * PHP files: encrypt(, encryptString(, mergeCasts( and withCasts( as a
 * function, a method or a static call. Read with PHP's tokenizer, so the
 * words in a comment, a string or a method's own name never count.
 */
final class EncryptingCalls
{
    /**
     * Each call as `path:line`, the path relative to $root with forward
     * slashes, files in name order.
     *
     * @return list<string>
     */
    public static function in(string $directory, string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $calls = [];

        foreach ((new Finder)->files()->in($directory)->name('*.php')->sortByName() as $file) {
            $tokens = token_get_all($file->getContents());
            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($root));

            foreach ($tokens as $index => $token) {
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                    continue;
                }

                $parts = explode('\\', $token[1]);
                $name = strtolower((string) end($parts));

                if (! in_array($name, ['encrypt', 'encryptstring', 'mergecasts', 'withcasts'], true)) {
                    continue;
                }

                if (self::neighbour($tokens, $index, 1) === '(' && self::neighbour($tokens, $index, -1) !== T_FUNCTION) {
                    $calls[] = $path.':'.$token[2];
                }
            }
        }

        return $calls;
    }

    /**
     * The nearest token that isn't space or a comment, one way or the
     * other: a character, a token's number, or null at the file's edge.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function neighbour(array $tokens, int $index, int $step): string|int|null
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }
}
