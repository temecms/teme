<?php

/**
 * The site as every theme view sees it, as `$site`: its name and tagline, its
 * home address, and its language, both as a locale for PHP, such as "pt_BR",
 * and as the BCP 47 tag `lang` takes, such as "pt-BR"; and the current year on
 * the site's clock, for a footer's copyright.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * The site as every theme view sees it, as `$site`: its name and tagline,
 * its home address, and its language, both as a locale for PHP,
 * such as "pt_BR", and as the BCP 47 tag `lang` takes, such as "pt-BR";
 * and the current year on the site's clock, for a footer's copyright; the
 * posts page's address and each type's archive page's.
 */
final readonly class SiteView
{
    /**
     * @param  string  $name  the site's name
     * @param  string  $tagline  the site's tagline
     * @param  string  $url  the site's home address
     * @param  string  $locale  the site's default language as a locale for PHP, such as pt_BR
     * @param  string  $language  the site's default language as the BCP 47 tag `lang` takes, such as pt-BR
     * @param  int  $year  the current year on the site's clock
     * @param  string  $postsUrl  the address of the page listing the latest posts, in the page's language: the posts page when Settings › Reading names one, else the home page
     * @param  array<string, string>  $archives  the address of each public type's archive page, by type key, in the page's language; the first public type's, which lists the latest posts, is $postsUrl
     */
    public function __construct(
        public string $name,
        public string $tagline,
        public string $url,
        public string $locale,
        public string $language,
        public int $year,
        public string $postsUrl = '',
        public array $archives = [],
    ) {}
}
