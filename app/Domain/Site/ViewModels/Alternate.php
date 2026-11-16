<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One language version of the page being drawn, as `$alternates` lists it
 * (I-3360): its language's tag and own name, its address, absolute, whether
 * it is this page, and whether it is in the site's default language.
 */
final readonly class Alternate
{
    /**
     * @param  string  $tag  the version's language as the BCP 47 tag `hreflang` takes, such as fr
     * @param  string  $name  the language's own name, such as Français
     * @param  string  $url  the version's address, absolute
     * @param  bool  $current  whether it is the page being drawn
     * @param  bool  $isDefault  whether it is in the site's default language
     */
    public function __construct(
        public string $tag,
        public string $name,
        public string $url,
        public bool $current,
        public bool $isDefault,
    ) {}
}
