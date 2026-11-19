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
 * and the current year on the site's clock, for a footer's copyright.
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
     */
    public function __construct(
        public string $name,
        public string $tagline,
        public string $url,
        public string $locale,
        public string $language,
        public int $year,
    ) {}
}
