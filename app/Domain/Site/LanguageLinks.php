<?php

/**
 * The links a language switcher draws (I-3360), one for each of the page's
 * versions in `$alternates`, so the `languages` menu item and
 * `<x-teme::languages>` give the same list: each labelled by
 * its language's own name, or by its short code when names are off, at its
 * address on this site, the page's own marked current or left out, with
 * the language it is in.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Site\ViewModels\Alternate;

final readonly class LanguageLinks
{
    public function __construct(private Alternates $alternates) {}

    /**
     * The page's versions as links; [] when it has no other version.
     *
     * @return list<array{label: string, url: string, current: bool, language: string}>
     */
    public function links(bool $showNames = true, bool $hideCurrent = false): array
    {
        return self::from($this->alternates->current(), $showNames, $hideCurrent);
    }

    /**
     * These versions as links.
     *
     * @param  list<Alternate>  $alternates
     *
     * @return list<array{label: string, url: string, current: bool, language: string}>
     */
    public static function from(array $alternates, bool $showNames = true, bool $hideCurrent = false): array
    {
        $root = rtrim(url('/'), '/');
        $links = [];

        foreach ($alternates as $alternate) {
            if ($hideCurrent && $alternate->current) {
                continue;
            }

            $links[] = [
                'label' => $showNames ? $alternate->name : mb_strtoupper(explode('-', $alternate->tag)[0]),
                'url' => str_starts_with($alternate->url, $root.'/') ? substr($alternate->url, strlen($root)) : $alternate->url,
                'current' => $alternate->current,
                'language' => $alternate->tag,
            ];
        }

        return $links;
    }
}
