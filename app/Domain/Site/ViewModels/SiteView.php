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

use App\Domain\Site\SiteAddresses;

/**
 * The site as every theme view sees it, as `$site`: its name and tagline,
 * its home address, and its language, both as a locale for PHP,
 * such as "pt_BR", and as the BCP 47 tag `lang` takes, such as "pt-BR";
 * and the current year on the site's clock, for a footer's copyright; the
 * posts page's address and each type's archive page's.
 */
final class SiteView
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
        public readonly string $name,
        public readonly string $tagline,
        public readonly string $url,
        public readonly string $locale,
        public readonly string $language,
        public readonly int $year,
        private readonly ?SiteAddresses $addresses = null,
    ) {}

    /**
     * The address of the page listing the latest posts, in the page's language: the posts page when Settings › Reading names one, else the home page while it shows the latest posts; an empty string while a static front page is shown and no posts page is live, since no page lists them. Read the first time a template asks, never otherwise.
     */
    public string $postsUrl {
        get => $this->addresses?->posts() ?? '';
    }

    /**
     * The address of each public type's archive page, by type key, in the page's language; the first public type's, which lists the latest posts, is `$postsUrl` and has no key while that is empty. Read the first time a template asks, never otherwise.
     *
     * @var array<string, string>
     */
    public array $archives {
        get => $this->addresses?->archives() ?? [];
    }
}
