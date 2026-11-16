<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Notices;

use InvalidArgumentException;
use Teme\Contracts\Notices\NoticeMessage;

/**
 * What a plugin's notice may say (docs/plans/plugin-sockets-3.md):
 * plain words of a set length, no one's quoted words, and an action that
 * leads only to the plugin's own settings or an item's editor. Core's
 * layout escapes every word as it does for its own notices.
 */
final class PluginNoticeRules
{
    /** At most this many characters in a subject. */
    public const int SUBJECT = 120;

    /** At most this many lines. */
    public const int LINES = 5;

    /** At most this many characters in a line. */
    public const int LINE = 500;

    /** A content item's ID. */
    private const string ULID = '/^[0-9a-hjkmnp-tv-z]{26}$/iD';

    /**
     * @throws InvalidArgumentException when the message breaks a rule
     */
    public static function check(NoticeMessage $message, string $code): void
    {
        $subject = trim($message->subject);

        if ($subject === '' || mb_strlen($subject) > self::SUBJECT) {
            throw new InvalidArgumentException(__('A plugin’s notice needs a subject of 1 to 120 characters.'));
        }

        if (count($message->lines) > self::LINES) {
            throw new InvalidArgumentException(__('A plugin’s notice has at most 5 lines of up to 500 characters each.'));
        }

        foreach ($message->lines as $line) {
            if (mb_strlen($line) > self::LINE) {
                throw new InvalidArgumentException(__('A plugin’s notice has at most 5 lines of up to 500 characters each.'));
            }
        }

        if ($message->quote !== null) {
            throw new InvalidArgumentException(__('A plugin’s notice can’t quote anyone.'));
        }

        if ($message->actionRoute !== null && ! self::actionAllowed($message->actionRoute, $message->actionParameters, $code)) {
            throw new InvalidArgumentException(__('A plugin’s notice may link only to its own settings or an item’s editor.'));
        }
    }

    /**
     * @param  array<string, string|int>  $parameters
     */
    private static function actionAllowed(string $route, array $parameters, string $code): bool
    {
        return match ($route) {
            'settings.show' => $parameters === ['group' => 'plugin.'.$code],
            'content.edit' => array_keys($parameters) === ['content'] && is_string($parameters['content']) && preg_match(self::ULID, $parameters['content']) === 1,
            default => false,
        };
    }
}
