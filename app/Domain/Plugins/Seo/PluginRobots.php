<?php

/**
 * A plugin's robots value for a page, laid over core's
 * (docs/guides/developers/plugins/seo.md): only the directives in ALLOWED,
 * and only ever tightening, so a page core keeps out of search stays out
 * whatever the plugin answers.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Site\Document\MetaTag;

final class PluginRobots
{
    /** The directives a plugin may give, `max-image-preview` with one of PREVIEWS. */
    public const array ALLOWED = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet'];

    /** `max-image-preview`'s values, the most private first. */
    public const array PREVIEWS = ['none', 'standard', 'large'];

    /**
     * Core's directives with the plugin's laid over them: its noindex,
     * nofollow, noarchive and nosnippet added, and its image preview kept
     * only where it is smaller than core's. Its index and follow change
     * nothing, so core's noindex or nofollow always stays.
     *
     * @param  list<string>  $core  core's directives, in the order they are written
     *
     * @return list<string>
     *
     * @throws SocketAnswerRefused for an answer with no directive, or one outside ALLOWED
     */
    public static function over(array $core, string $answer): array
    {
        $given = self::parse($answer);
        $directives = $core;

        if (in_array('noindex', $given, true) && ! in_array('noindex', $directives, true)) {
            array_unshift($directives, 'noindex');
        }

        if (in_array('nofollow', $given, true) && ! in_array('nofollow', $directives, true)) {
            $follow = array_search('follow', $directives, true);

            if ($follow !== false) {
                $directives[$follow] = 'nofollow';
            } else {
                $noindex = array_search('noindex', $directives, true);
                array_splice($directives, $noindex === false ? 0 : $noindex + 1, 0, ['nofollow']);
            }
        }

        if (in_array('nosnippet', $given, true) && ! in_array('nosnippet', $directives, true)) {
            $snippet = array_key_first(array_filter($directives, fn (string $directive): bool => str_starts_with($directive, 'max-snippet:')));

            if ($snippet !== null) {
                $directives[$snippet] = 'nosnippet';
            } else {
                $directives[] = 'nosnippet';
            }
        }

        $preview = array_values(array_filter($given, fn (string $directive): bool => str_starts_with($directive, 'max-image-preview:')))[0] ?? null;

        if ($preview !== null) {
            $directives = self::preview($directives, $preview);
        }

        if (in_array('noarchive', $given, true) && ! in_array('noarchive', $directives, true)) {
            $directives[] = 'noarchive';
        }

        return array_values($directives);
    }

    /**
     * The robots tag to write for the plugin's answer over core's
     * directives, or null when there are none to write: on a page where core
     * writes none, an answer of only `index` or `follow` changes nothing,
     * and an empty tag is never drawn.
     *
     * @param  list<string>  $core  core's directives, in the order they are written
     *
     * @throws SocketAnswerRefused as over() does
     */
    public static function tag(array $core, string $answer): ?MetaTag
    {
        $directives = self::over($core, $answer);

        return $directives === [] ? null : MetaTag::name('robots', implode(', ', $directives));
    }

    /**
     * The answer's directives, lower-cased, or a refusal naming what isn't allowed.
     *
     * @return non-empty-list<string>
     *
     * @throws SocketAnswerRefused
     */
    private static function parse(string $answer): array
    {
        $given = array_values(array_filter(array_map(fn (string $part): string => strtolower(trim($part)), explode(',', $answer)), fn (string $part): bool => $part !== ''));

        if ($given === []) {
            throw new SocketAnswerRefused('The robots value names no directive.');
        }

        foreach ($given as $directive) {
            $allowed = in_array($directive, self::ALLOWED, true)
                || (str_starts_with($directive, 'max-image-preview:') && in_array(substr($directive, 18), self::PREVIEWS, true));

            if (! $allowed) {
                throw new SocketAnswerRefused("The robots value [{$directive}] isn't one a plugin may give: ".implode(', ', self::ALLOWED).', or max-image-preview: with '.implode(', ', self::PREVIEWS).'.');
            }
        }

        return $given;
    }

    /**
     * The directives with the smaller of core's image preview and the plugin's.
     *
     * @param  list<string>  $directives
     *
     * @return list<string>
     */
    private static function preview(array $directives, string $preview): array
    {
        foreach ($directives as $at => $directive) {
            if (str_starts_with($directive, 'max-image-preview:')) {
                $core = array_search(substr($directive, 18), self::PREVIEWS, true);
                $plugin = array_search(substr($preview, 18), self::PREVIEWS, true);
                $directives[$at] = $core !== false && $plugin !== false && $plugin < $core ? $preview : $directive;

                return $directives;
            }
        }

        $directives[] = $preview;

        return $directives;
    }
}
