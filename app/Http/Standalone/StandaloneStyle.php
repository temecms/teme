<?php

declare(strict_types=1);

namespace App\Http\Standalone;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * The admin's design tokens as plain CSS, for the pages drawn outside the
 * admin's app (the error pages and the holding page): read from the token
 * block of resources/css/app.css, so they never drift from it. It also
 * names the stylesheet of the admin's own fonts, for those pages to link.
 */
final class StandaloneStyle
{
    /** The build's stylesheet of the admin's fonts and nothing else. */
    public const FONTS = 'resources/css/admin-fonts.css';

    private ?string $tokens = null;

    /**
     * The address of the admin's fonts in the build; null when there is no
     * build to read, so the page still renders, in the system's fonts.
     */
    public function fonts(): ?string
    {
        try {
            $href = Vite::asset(self::FONTS);
        } catch (Throwable) {
            return null;
        }

        return $href !== '' ? $href : null;
    }

    /**
     * The token block as plain CSS: Tailwind's `@theme` values as `:root`,
     * the dark values under the system's dark scheme; an empty string when
     * the file or its markers are missing, so the page still renders.
     */
    public function tokens(): string
    {
        return $this->tokens ??= $this->build((string) @file_get_contents(resource_path('css/app.css')));
    }

    /**
     * The plain CSS for one source of app.css.
     */
    public function build(string $source): string
    {
        $start = strpos($source, '/* @tokens */');
        $end = strpos($source, '/* @end-tokens */');

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return $this->rewrite((string) preg_replace('#/\*.*?\*/#s', '', substr($source, $start, $end - $start)));
    }

    /**
     * Each top-level rule, rewritten: `@theme` becomes `:root` holding only
     * its declarations; `@theme inline` (Tailwind's color names) goes;
     * `.dark` becomes `:root` under `prefers-color-scheme: dark`; a media
     * query is rewritten inside; anything else is kept as written.
     */
    private function rewrite(string $css): string
    {
        $out = '';

        foreach ($this->rules($css) as [$prelude, $body]) {
            $out .= match (true) {
                $prelude === '@theme' => ':root{'.$this->declarationsOnly($body).'}',
                str_starts_with($prelude, '@theme') => '',
                $prelude === '.dark' => '@media (prefers-color-scheme: dark){:root{'.$body.'}}',
                str_starts_with($prelude, '@media') => $prelude.'{'.$this->rewrite($body).'}',
                default => $prelude.'{'.$body.'}',
            };
        }

        return $out;
    }

    /**
     * The top-level rules of $css: each prelude and its body, braces matched.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rules(string $css): array
    {
        $rules = [];
        $depth = 0;
        $prelude = '';
        $body = '';

        foreach (str_split($css) as $char) {
            if ($char === '{' && $depth++ === 0) {
                continue;
            }

            if ($char === '}' && --$depth === 0) {
                $rules[] = [trim($prelude), $body];
                $prelude = '';
                $body = '';

                continue;
            }

            if ($depth === 0) {
                $prelude .= $char;
            } else {
                $body .= $char;
            }
        }

        return $rules;
    }

    /**
     * A block's own declarations: nested blocks (the shimmer's keyframes)
     * and Tailwind's `--name-*: initial` resets removed.
     */
    private function declarationsOnly(string $body): string
    {
        $flat = (string) preg_replace('/[^;{}]*\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $body);

        return (string) preg_replace('/--[a-z0-9-]+-\*\s*:\s*initial\s*;/i', '', $flat);
    }
}
