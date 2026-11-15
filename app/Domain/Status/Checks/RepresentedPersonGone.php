<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\StructuredData\PublisherKinds;
use App\Domain\StructuredData\WhoPublishes;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Says when the site represents a person who no longer has an account
 * (I-878): search engines are then told nobody in particular publishes
 * it, until someone else is chosen. It goes when the choice is made again.
 */
final readonly class RepresentedPersonGone implements StatusCheck
{
    public function __construct(private SettingsReader $settings) {}

    public function ability(): string
    {
        return SearchAndSocial::ABILITY;
    }

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
