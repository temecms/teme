<?php

/**
 * The language of the page being drawn, as every theme view sees it, as
 * `$language`: its BCP 47 tag, its PHP locale, its own name, whether it is the
 * site's default, and its home address.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * The language of the page being drawn, as every theme view sees it, as
 * `$language`: its BCP 47 tag, its PHP locale, its own name,
 * whether it is the site's default, and its home address.
 */
final readonly class PageLanguageView
{
    /**
     * @param  string  $tag  the page's language as the BCP 47 tag `lang` takes, such as fr or pt-BR
     * @param  string  $locale  the page's language as a locale for PHP, such as fr or pt_BR
     * @param  string  $name  the language's own name, such as Français
     * @param  bool  $isDefault  whether it is the site's default language
     * @param  string  $home  that language's home address, absolute
     */
    public function __construct(
        public string $tag,
        public string $locale,
        public string $name,
        public bool $isDefault,
        public string $home,
    ) {}
}
