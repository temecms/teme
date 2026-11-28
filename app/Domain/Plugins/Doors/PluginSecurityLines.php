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

    /** The type every plugin's lines share, which no core event takes. */
    public const string TYPE = 'plugin.note';

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

    public function write(string $kind, string $text, int|string|null $userId = null, ?string $address = null): bool
    {
        if (! array_key_exists($kind, $this->kinds)) {
            throw new InvalidArgumentException("The security log kind [{$kind}] is not one the plugin declares.");
        }

        $text = trim($text);

        if ($address !== null && filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('A security log line’s address is an IP address.');
        }

        if ($text === '' || mb_strlen($text) > self::MOST_TEXT) {
            throw new InvalidArgumentException('A security log line is text of 1 to 200 characters.');
        }

        // One atomic step counts the line before it is written, so two requests can't both pass the cap.
        $key = 'plugin-security-lines:'.$this->plugin;
        Cache::add($key, 0, self::HOUR);

        if (Cache::increment($key) > self::MOST_PER_HOUR) {
            return false;
        }

        // One type no core event takes, whatever the plugin's code: the code and the kind are in the context.
        $this->log->recordNamed(self::TYPE, $userId, $address, ['plugin' => $this->plugin, 'kind' => $kind, 'text' => $text]);

        return true;
    }
}
