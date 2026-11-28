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

use DateTimeInterface;
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

    /** The most detail rows one view may hold. @since next */
    public const int MOST_ROWS = 10;

    /** The longest saved value, in characters. @since next */
    public const int MOST_SAVED = 2000;

    /**
     * @param  string|null  $status  up to 200 characters of plain text
     * @param  string  $tone  ok, neutral, info, warn or danger
     * @param  list<Suggestion>  $suggestions  at most 20, each id once
     * @param  list<PanelRow>  $rows  at most 10 labelled lines of detail, shown above the preview
     * @param  PanelPreview|null  $preview  the preview card of what the plugin will send, or null for none
     * @param  array<string, string|int|float|bool|null>  $saved  the saved values of the action fields, by field key; they start a field the person hasn't touched
     * @param  DateTimeInterface|null  $savedAt  when the saved values were saved, or null
     * @param  string|null  $rowsHeading  up to 60 characters: the heading over the rows, or null for "Details"
     *
     * @throws InvalidArgumentException for a status, tone or list outside those bounds
     *
     * @since 1.0.0
     */
    public function __construct(
        public ?string $status = null,
        public string $tone = 'neutral',
        public array $suggestions = [],
        public array $rows = [],
        public ?PanelPreview $preview = null,
        public array $saved = [],
        public ?DateTimeInterface $savedAt = null,
        public ?string $rowsHeading = null,
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

        if (count($rows) > self::MOST_ROWS) {
            throw new InvalidArgumentException('A panel may hold at most 10 detail rows.');
        }

        foreach ($saved as $value) {
            if (is_string($value) && mb_strlen($value) > self::MOST_SAVED) {
                throw new InvalidArgumentException('A saved value may be at most 2000 characters.');
            }
        }

        if ($rowsHeading !== null && ($rowsHeading === '' || mb_strlen($rowsHeading) > 60)) {
            throw new InvalidArgumentException('A rows heading must be 1 to 60 characters.');
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
