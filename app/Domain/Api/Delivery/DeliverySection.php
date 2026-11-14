<?php

declare(strict_types=1);

namespace App\Domain\Api\Delivery;

use App\Domain\Seo\SearchAndSocialSection;
use Teme\Contracts\Settings\Setting;

/**
 * Search and social's section "Apps and AI tools" (I-3359): the two
 * switches that let apps read the published content through the API and
 * publish llms.txt, each off on a new site (I-713). Their help gives the
 * full address, made from the site's address when the section is read.
 */
final class DeliverySection implements SearchAndSocialSection
{
    public function order(): int
    {
        return 750;
    }

    public function key(): string
    {
        return 'apps-and-ai';
    }

    public function label(): string
    {
        return 'Apps and AI tools';
    }

    public function settings(): array
    {
        $site = rtrim(url('/'), '/');

        return [
            Setting::boolean(
                DeliverySwitches::API,
                'Let apps and other sites read your published content as data',
                strtr('Apps, other sites and tools can read your published content as data through the API, at :address: posts, pages and your own types, with their fields, terms and images. They see only what readers see: never drafts, private items, what’s behind a password, or fields you hide on the site.', [':address' => $site.'/api/v1']),
                '0.3.0',
            ),
            Setting::boolean(
                DeliverySwitches::LLMS,
                'Publish llms.txt',
                strtr('A short guide to your site at :address, for AI assistants and coding tools that someone points at it, with a plain-text copy of each listed post and page at its address followed by .md. It lists only what search engines may list, and nothing while the site discourages search engines.', [':address' => $site.'/llms.txt']),
                '0.3.0',
            ),
        ];
    }
}
