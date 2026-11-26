<?php

/**
 * Core's composer, run before any theme's on every theme view: `site`, the
 * site's name, tagline, home address, language and year, and `isFront`, true
 * only on the home page and its numbered pages (I-442), and `teme`, the
 * version of Teme the site runs and its release. The language is the
 * site language setting's (I-435), the default, whatever the page's own
 * (I-3360), which `$language` gives.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes\CoreComposers;

use App\Domain\Site\SiteAddresses;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\SiteView;
use App\Domain\Updates\TemeVersion;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

final readonly class SiteComposer implements ViewComposer
{
    public function __construct(private SettingsReader $settings, private SiteLanguage $site, private SiteAddresses $addresses) {}

    public function views(): array
    {
        return ['*'];
    }

    public function with(ViewContext $context): array
    {
        $language = $this->site->tag();
        $version = TemeVersion::current();

        return [
            'site' => new SiteView(
                name: $this->settings->string('general', 'site_title'),
                tagline: $this->settings->string('general', 'tagline'),
                url: rtrim(url('/'), '/').'/',
                locale: str_replace('-', '_', $language),
                language: $language,
                year: SiteDates::inSite('now')->year,
                postsUrl: $this->addresses->posts(),
                archives: $this->addresses->archives(),
            ),
            'isFront' => $context->isFront(),
            'teme' => ['version' => $version, 'release' => $this->release($version)],
        ];
    }

    /**
     * The release a version belongs to: its major and minor, so 1.2.0 and
     * 1.2.0-rc.1 are both 1.2. A version with no such shape, an untagged
     * build, is its own release.
     */
    private function release(string $version): string
    {
        return preg_match('/^(\d+\.\d+)\./', $version, $found) === 1 ? $found[1] : $version;
    }
}
