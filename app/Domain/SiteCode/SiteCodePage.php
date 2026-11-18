<?php

/**
 * Settings › Site code's tab (I-863): after the settings groups, for whoever
 * holds site-code.manage. Its key is its address, `site-code`, which no
 * group's key can be.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Settings\SettingsPage;

final class SiteCodePage implements SettingsPage
{
    public function key(): string
    {
        return 'site-code';
    }

    public function label(): string
    {
        return 'Site code';
    }

    public function ability(): string
    {
        return SiteCode::ABILITY;
    }
}
