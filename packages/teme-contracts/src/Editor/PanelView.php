<?php

/**
 * A short status line, its tone, and the suggestions waiting for the person.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use InvalidArgumentException;
use Teme\Contracts\Lists\Cell;

/**
 * A short status line, its tone, and the suggestions waiting for the person.
 *
 * @since 1.0.0
 */
final readonly class PanelView
{
    /** The most suggestions one view may hold. @since 1.0.0 */
    public const int MOST_SUGGESTIONS = 20;

    /** The longest status line, in characters. @since 1.0.0 */
    public const int MOST_STATUS = 200;

    /**
     * @param  string|null  $status  up to 200 characters of plain text
     * @param  string  $tone  ok, neutral, info, warn or danger
     * @param  list<Suggestion>  $suggestions  at most 20, each id once
     *
     * @throws InvalidArgumentException for a status, tone or list outside those bounds
     *
     * @since 1.0.0
     */
    public function __construct(
        public ?string $status = null,
        public string $tone = 'neutral',
        public array $suggestions = [],
    ) {
        if ($status !== null && mb_strlen($status) > self::MOST_STATUS) {
            throw new InvalidArgumentException('A panel’s status may be at most 200 characters.');
        }

        if (! in_array($tone, Cell::TONES, true)) {
            throw new InvalidArgumentException('A panel’s tone must be ok, neutral, info, warn or danger.');
        }

        if (count($suggestions) > self::MOST_SUGGESTIONS) {
            throw new InvalidArgumentException('A panel may hold at most 20 suggestions.');
        }

        $ids = [];

        foreach ($suggestions as $suggestion) {
            if (isset($ids[$suggestion->id])) {
                throw new InvalidArgumentException('Two suggestions can’t share an id.');
            }

            $ids[$suggestion->id] = true;
        }
    }
}
