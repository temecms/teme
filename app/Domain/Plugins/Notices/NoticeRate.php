<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Notices;

use App\Domain\Hooks\ListenerFaults;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Teme\Contracts\Notices\Notices;

/**
 * How many notices a plugin may deliver
 * (docs/plans/plugin-sockets-3.md): at most 100 an hour, counted per
 * recipient. Those beyond are dropped, and one fault an hour is recorded
 * under the plugin.
 */
final class NoticeRate
{
    /** Deliveries a plugin may make in an hour. */
    public const int MOST = 100;

    private const int HOUR = 3600;

    /**
     * How many of these recipients the plugin may still reach this hour,
     * counted as taken; when it is fewer than asked, the fault is recorded.
     */
    public function take(string $plugin, int $recipients): int
    {
        $key = 'plugin-notices:'.$plugin;
        $allowed = min($recipients, RateLimiter::remaining($key, self::MOST));

        for ($i = 0; $i < $allowed; $i++) {
            RateLimiter::hit($key, self::HOUR);
        }

        if ($allowed < $recipients && Cache::add('plugin-notices-fault:'.$plugin, true, self::HOUR)) {
            app(ListenerFaults::class)->record($plugin, $plugin.'#notices', Notices::class, new RuntimeException(__('It sent more than 100 notices in an hour; the rest were dropped.')));
        }

        return max(0, $allowed);
    }
}
