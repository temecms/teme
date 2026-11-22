<?php

/**
 * How many notices a plugin may deliver
 * (docs/plans/plugin-sockets-3.md): at most 100 an hour, counted per
 * recipient, in one atomic increment. Those beyond are dropped, and one fault an hour is recorded
 * under the plugin.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Notices;

use App\Domain\Hooks\ListenerFaults;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Teme\Contracts\Notices\Notices;

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
        $key = 'plugin-notices-count:'.$plugin;

        // One atomic step takes them all: two processes can't both read the same count and both pass the cap.
        Cache::add($key, 0, self::HOUR);
        $total = (int) Cache::increment($key, $recipients);
        $allowed = min($recipients, self::MOST - ($total - $recipients));

        if ($allowed < $recipients && Cache::add('plugin-notices-fault:'.$plugin, true, self::HOUR)) {
            app(ListenerFaults::class)->record($plugin, $plugin.'#notices', Notices::class, new RuntimeException(__('It sent more than 100 notices in an hour; the rest were dropped.')));
        }

        return max(0, $allowed);
    }
}
