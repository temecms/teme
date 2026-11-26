<?php

/**
 * Says when the site represents a person who no longer has an account
 * (I-878): search engines are then told nobody in particular publishes
 * it, until someone else is chosen. It goes when the choice is made again.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\StructuredData\PublisherKinds;
use App\Domain\StructuredData\WhoPublishes;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final readonly class RepresentedPersonGone implements StatusCheck
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since 1.6.0
     */
    public function key(): string
    {
        return 'represented-person-gone';
    }

    public function ability(): string
    {
        return SearchAndSocial::ABILITY;
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Search and social');
    }

    public function banner(): ?StatusBanner
    {
        $id = $this->settings->get(SearchAndSocial::KEY, WhoPublishes::PERSON);

        if (WhoPublishes::kind() !== PublisherKinds::PERSON || ! is_string($id) || $id === '') {
            return null;
        }

        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) === 1 && DB::table('users')->where('id', (int) $id)->exists()) {
            return null;
        }

        return new StatusBanner(
            'warning',
            'The person the site represents no longer has an account, so search engines are told nobody in particular publishes it.',
            'Choose who publishes the site',
            route('settings.seo').'#section-who-publishes',
            // Below the menus' (50), above search being rebuilt (10).
            40,
        );
    }
}
