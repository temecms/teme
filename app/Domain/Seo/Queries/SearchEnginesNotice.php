<?php

/**
 * Home's warning while the site asks search engines to stay away (I-873),
 * as WordPress's "Search engines discouraged": for whoever may change Search
 * and social, linking to the switch.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Seo\Queries;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Models\User;

final readonly class SearchEnginesNotice
{
    /**
     * The warning for this person, or null when they may not change Search
     * and social or search engines may list the site. The ability is asked
     * first, so nobody else's Home reads the setting.
     *
     * @return array{message: string, detail: string, actionLabel: string, actionUrl: string}|null
     */
    public function forUser(User $viewer): ?array
    {
        if (! $viewer->can(SearchAndSocial::ABILITY) || ! WholeSite::discouraged()) {
            return null;
        }

        return [
            'message' => __('Search engines are asked to stay away from this site.'),
            'detail' => __('Every page asks search engines not to list it, and the sitemap is off.'),
            'actionLabel' => __('Open Search and social'),
            'actionUrl' => route('settings.seo').'#section-whole-site',
        ];
    }
}
