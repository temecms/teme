<?php

/**
 * Search and social's section "robots.txt" (I-300): the lines an
 * administrator adds to the file, checked line by line when saved, and the
 * switch that asks AI training crawlers to stay away.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\RobotsTxt;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsCheck;
use Teme\Contracts\Settings\Setting;

final class RobotsTxtSection implements SearchAndSocialSection, SettingsCheck
{
    public const string EXTRA = 'robots_extra_rules';

    public const string AI = 'discourage_ai_training';

    public function order(): int
    {
        return 700;
    }

    public function key(): string
    {
        return 'robots-txt';
    }

    public function label(): string
    {
        return 'robots.txt';
    }

    public function settings(): array
    {
        return [
            Setting::textarea(self::EXTRA, 'Extra rules', 'Added to the robots.txt Teme writes, after its own rules. Each line is checked: User-agent, Allow, Disallow or Sitemap, or # for a note.', '0.1.0')
                ->maxLength(20000),
            Setting::boolean(self::AI, 'Ask AI training crawlers not to use this site', 'Asks each AI training crawler on Teme’s list not to use the site, while still letting search engines in. It’s a request, not a lock: well-behaved crawlers follow it. It also keeps the site out of answers in Google’s Gemini apps, but not out of Google’s AI Overviews, which are part of Search, or pages a reader asks an assistant to fetch.', '0.1.0'),
        ];
    }

    public function group(): string
    {
        return SearchAndSocial::KEY;
    }

    public function problems(array $values): array
    {
        $extra = $values[self::EXTRA] ?? null;

        if (! is_string($extra)) {
            return [];
        }

        $problem = RobotsRules::firstProblem($extra);

        return $problem === null ? [] : ['values.'.self::EXTRA => $problem];
    }
}
