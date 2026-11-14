<?php

declare(strict_types=1);

namespace App\Domain\Site\Head;

use Closure;

/**
 * A title template filled in (I-290): Teme's own tokens, each replaced once,
 * with empty pieces and the separators beside them dropped, as Yoast and
 * Rank Math collapse theirs. The editor's previews follow the same rule.
 */
final class TitleTemplate
{
    /** The tokens a template may use, in the order Search and social lists them (I-851). */
    public const array TOKENS = ['title', 'site', 'tagline', 'sep', 'page', 'term', 'author', 'type', 'excerpt', 'date', 'year', 'search'];

    /**
     * The template cut at each {sep}; in each piece every token replaced in
     * one pass, so no value is read again for tokens, an unknown or missing
     * one by nothing; white space run together and trimmed; empty pieces
     * dropped; the rest joined by the separator, a space on each side.
     *
     * @param  array<string, string|Closure(): string>  $tokens  a closure is called only when the template uses its token
     */
    public static function fill(string $template, array $tokens, string $separator): string
    {
        preg_match_all('/\{([a-z]+)\}/', $template, $found);
        $values = [];

        foreach (array_unique($found[1]) as $name) {
            if ($name === 'sep') {
                continue;
            }

            $value = $tokens[$name] ?? '';
            $values['{'.$name.'}'] = $value instanceof Closure ? (string) $value() : $value;
        }

        $pieces = [];

        foreach (explode('{sep}', $template) as $piece) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strtr($piece, $values)));

            if ($text !== '') {
                $pieces[] = $text;
            }
        }

        $separator = trim($separator);

        return implode($separator === '' ? ' ' : " {$separator} ", $pieces);
    }
}
