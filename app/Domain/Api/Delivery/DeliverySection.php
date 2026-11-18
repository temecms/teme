<?php

declare(strict_types=1);

namespace App\Domain\Api\Delivery;

use App\Domain\RobotsTxt\RobotsTxtSection;
use App\Domain\Seo\SearchAndSocialSection;
use Teme\Contracts\Settings\Setting;

/**
 * Search and social's section "For apps and AI tools" (I-3359): the two
 * switches that let apps read the published content through the API and
 * publish llms.txt, each off on a new site (I-713), and the one that asks AI
 * training crawlers to stay away (I-300). Their help gives the
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
        return 'For apps and AI tools';
    }

    public function settings(): array
    {
        $site = rtrim(url('/'), '/');

        return [
            Setting::boolean(
                DeliverySwitches::API,
                'Let apps and other sites read the published content through the API',
                strtr('They see only what readers see, at :address: posts, pages and your own types, with their fields, terms and images. Never drafts, private items or what’s behind a password.', [':address' => $site.'/api/v1']),
                '0.3.0',
            ),
            Setting::boolean(
                DeliverySwitches::LLMS,
                'Publish an llms.txt guide to the site',
                strtr('A short guide at :address, for AI assistants and coding tools that someone points at it, with a plain-text copy of each listed post and page. It lists only what search engines may list.', [':address' => $site.'/llms.txt']),
                '0.3.0',
            ),
            Setting::boolean(
                RobotsTxtSection::AI,
                'Ask AI training crawlers not to use this site',
                'It’s a request, not a lock: well-behaved crawlers follow it. Search engines are still let in.',
                '0.1.0',
            ),
        ];
    }
}
