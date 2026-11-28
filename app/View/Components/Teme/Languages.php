<?php

/**
 * `<x-teme::languages />`: a link to each language version of the page, for a
 * theme that places its own switcher.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Site\LanguageLinks;
use App\Domain\Themes\ComponentOverrides;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::languages />`: a link to each language version of the page, for
 * a theme that places its own switcher. The same links as a menu's
 * language switcher, in a plain list with `lang` and `hreflang` on each and
 * `aria-current` on the page's own; nothing on a page with no other version.
 */
final class Languages extends Component
{
    /** The contract a theme's override of this component is written against (docs/guides/developers/themes/components.md). */
    public const int CONTRACT = 1;

    /** @var list<array{label: string, url: string, current: bool, language: string}> */
    public array $links;

    /**
     * @param  bool  $showNames  whether each link reads its language's own name, such as Français, rather than its short code, such as FR
     * @param  bool  $hideCurrent  whether the page's own language is left out
     */
    public function __construct(LanguageLinks $languages, bool $showNames = true, bool $hideCurrent = false)
    {
        $this->links = $languages->links($showNames, $hideCurrent);
    }

    public function render(): View
    {
        return app(ComponentOverrides::class)->render('languages', 'teme::components.languages');
    }
}
