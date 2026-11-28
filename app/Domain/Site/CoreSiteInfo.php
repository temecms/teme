<?php

/**
 * The site's public facts as Teme gives them to plugins
 * (docs/plans/plugin-sockets-1.md, A5): the address from the same source
 * Addresses::urlFor() reads, the name from Settings › General, and a main
 * feed for each language readers are shown.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Languages\SiteLanguages;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;

final readonly class CoreSiteInfo implements SiteInfo
{
    public function __construct(
        private SettingsReader $settings,
        private SiteLanguages $languages,
    ) {}

    public function address(): string
    {
        return rtrim(url('/'), '/');
    }

    public function name(): string
    {
        return $this->settings->string('general', 'site_title');
    }

    public function language(): string
    {
        return $this->languages->defaultTag();
    }

    public function home(): string
    {
        return $this->address().'/';
    }

    /**
     * Returns the main feed's absolute address for each shown language, the default's first.
     *
     * @return list<string>
     */
    public function feeds(): array
    {
        return array_map(function (string $tag): string {
            $prefix = $this->languages->prefix($tag);

            return $this->address().PublicRequestPath::encode($prefix === '' ? '/feed/' : "/{$prefix}/feed/");
        }, $this->languages->shown());
    }

    public function timeZone(): string
    {
        return $this->settings->string('general', 'timezone');
    }
}
