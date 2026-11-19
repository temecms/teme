<?php

/**
 * Registers Settings › Site code (I-288): its entries in every page's
 * placements, its ads.txt setting, and its tab under Settings. In
 * register(), as every part registers.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Settings\SettingsPage;
use App\Domain\Site\Document\Placements;
use App\Domain\SiteCode\SiteCodeFragments;
use App\Domain\SiteCode\SiteCodePage;
use App\Domain\SiteCode\SiteCodeSettings;
use App\Domain\SiteCode\SiteCodeWithheld;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

final class SiteCodeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([SiteCodeFragments::class], Placements::CONTRIBUTORS);
        $this->app->tag([SiteCodePage::class], SettingsPage::TAG);
        $this->app->scoped(SiteCodeWithheld::class);
    }

    /**
     * @since 1.0.0
     */
    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(SiteCodeSettings::group());
    }
}
