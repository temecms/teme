<?php

declare(strict_types=1);

namespace App\Domain\Themes\CoreComposers;

use App\Domain\Site\SiteDates;
use App\Domain\Site\ViewModels\SiteView;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

/**
 * Core's composer, run before any theme's on every theme view: `site`, the
 * site's name, tagline, home address, language and year, and `isFront`, true
 * only on the home page and its numbered pages (I-442). The language is the
 * site language setting's (I-435), as the context carries it.
 */
final readonly class SiteComposer implements ViewComposer
{
    public function __construct(private SettingsReader $settings) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        $language = $context->locale();

        return [
            'site' => new SiteView(
                name: $this->settings->string('general', 'site_title'),
                tagline: $this->settings->string('general', 'tagline'),
                url: rtrim(url('/'), '/').'/',
                locale: str_replace('-', '_', $language),
                language: $language,
                year: SiteDates::inSite('now')->year,
            ),
            'isFront' => $context->isFront(),
        ];
    }
}
