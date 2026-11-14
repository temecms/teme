<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Settings › AI's section "Limits" (I-320): how many requests one person may
 * make in an hour, and the month's budget in whole US dollars, 0 for
 * none. The budget is soft: requests already running may pass it.
 */
final class UsageLimits implements AiSettingsSection
{
    public const string HOURLY = 'hourly_limit';

    public const string BUDGET = 'monthly_budget';

    public function order(): int
    {
        return 300;
    }

    public function key(): string
    {
        return 'limits';
    }

    public function label(): string
    {
        return 'Limits';
    }

    public function settings(): array
    {
        return [
            Setting::number(self::HOURLY, 'Requests an hour, per person', 'How many times one person may ask AI for a suggestion in an hour.', '0.1.0')
                ->default(60)->min(1)->max(1000),
            Setting::number(self::BUDGET, 'Monthly budget, in US dollars', 'When this month’s recorded cost reaches it, AI is unavailable to everyone until the month ends. Requests already running may pass it a little. 0 means no budget.', '0.1.0')
                ->default(0)->min(0)->max(1_000_000),
        ];
    }

    public static function hourly(): int
    {
        return app(SettingsReader::class)->int(AiSettings::KEY, self::HOURLY);
    }

    /**
     * The month's budget in micro-dollars, or null when there is none.
     */
    public static function budgetMicrounits(): ?int
    {
        $dollars = app(SettingsReader::class)->int(AiSettings::KEY, self::BUDGET);

        return $dollars > 0 ? $dollars * 1_000_000 : null;
    }
}
