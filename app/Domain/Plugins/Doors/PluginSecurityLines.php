<?php

/**
 * The security log door a plugin is given when it asks for security.log: it
 * writes one line a time, of a kind its manifest declares, under the plugin's
 * name, and at most 60 an hour. A line about a person reaches their data
 * export like core's own lines; the log is pruned on the same schedule.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Security\SecurityLog;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Teme\Contracts\Security\SecurityLines;

final readonly class PluginSecurityLines implements SecurityLines
{
    /** Lines a plugin may write in an hour. */
    public const int MOST_PER_HOUR = 60;

    /** The longest text of a line, in characters. */
    public const int MOST_TEXT = 200;

    private const int HOUR = 3600;

    /**
     * @param  string  $plugin  the plugin's code, which names its lines
     * @param  array<string, string>  $kinds  the kinds its manifest declares, by id
     */
    public function __construct(
        private string $plugin,
        private array $kinds,
        private SecurityLog $log,
    ) {}

    public function write(string $kind, string $text, int|string|null $userId = null, ?string $email = null): bool
    {
        if (! array_key_exists($kind, $this->kinds)) {
            throw new InvalidArgumentException("The security log kind [{$kind}] is not one the plugin declares.");
        }

        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MOST_TEXT) {
            throw new InvalidArgumentException('A security log line is text of 1 to 200 characters.');
        }

        // One atomic step counts the line before it is written, so two requests can't both pass the cap.
        $key = 'plugin-security-lines:'.$this->plugin;
        Cache::add($key, 0, self::HOUR);

        if (Cache::increment($key) > self::MOST_PER_HOUR) {
            return false;
        }

        // The type holds the plugin's code alone, so it fits the log's 64-character column; the kind is in the context.
        $this->log->recordNamed('plugin.'.$this->plugin, $userId, $email, ['kind' => $kind, 'text' => $text]);

        return true;
    }
}
