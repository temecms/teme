<?php

/**
 * What Search and social's section "Who publishes the site" draws beside its
 * fields (I-874, I-878): the kinds in their groups, the site's title an empty
 * name falls back to, and whether the chosen person's account is gone.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Seo\Queries;

use App\Domain\Status\Checks\RepresentedPersonGone;
use App\Domain\StructuredData\PublisherKinds;
use Teme\Contracts\Settings\SettingsReader;

final readonly class PublisherScreen
{
    public function __construct(
        private SettingsReader $settings,
        private RepresentedPersonGone $personGone,
    ) {}

    /**
     * The section's extras: each kind in the order the select lists them with
     * the group it sits in (null for Nobody in particular and A person), the
     * site's title, and whether the person the site represents has no account.
     *
     * @return array{kinds: list<array{value: string, label: string, group: string|null}>, siteTitle: string, personGone: bool}
     */
    public function data(): array
    {
        $kinds = [];

        foreach (PublisherKinds::options() as $value => $label) {
            $kinds[] = [
                'value' => (string) $value,
                'label' => $label,
                'group' => match (true) {
                    isset(PublisherKinds::ORGANIZATIONS[$value]) => 'An organization',
                    isset(PublisherKinds::BUSINESSES[$value]) => 'A local business',
                    default => null,
                },
            ];
        }

        return [
            'kinds' => $kinds,
            'siteTitle' => $this->settings->string('general', 'site_title'),
            'personGone' => $this->personGone->banner() !== null,
        ];
    }
}
