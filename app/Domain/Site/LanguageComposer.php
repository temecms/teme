<?php

/**
 * Core's composer of the three names a page's language fills (I-3360), run
 * on every theme view: `$language`, the page's language with its own name
 * and home address; `$direction`, `ltr` or `rtl`; and `$alternates`, every
 * shown version of the page a reader can open, `[]` when there is only the
 * page itself.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ViewModels\PageLanguageView;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

final readonly class LanguageComposer implements ViewComposer
{
    public function __construct(
        private SiteLanguages $languages,
        private PageLanguage $page,
        private Alternates $alternates,
    ) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        $tag = $this->page->tag();
        $prefix = $this->languages->prefix($tag);

        return [
            'language' => new PageLanguageView(
                tag: $tag,
                locale: $this->languages->locale($tag),
                name: $this->languages->native($tag),
                isDefault: $tag === $this->languages->defaultTag(),
                home: rtrim(url('/'), '/').($prefix === '' ? '/' : '/'.$prefix.'/'),
            ),
            'direction' => $this->languages->direction($tag),
            'alternates' => $this->alternates->current(),
        ];
    }
}
