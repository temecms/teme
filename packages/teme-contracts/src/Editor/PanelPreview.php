<?php

/**
 * The preview card a panel shows of what it will send: a picture chosen from
 * the media library, a title and a description. The same shape for every
 * plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use InvalidArgumentException;

/**
 * The preview card a panel shows of what it will send. Core draws the card,
 * takes the picture from the media library by its ID and shows the site's
 * own host; the plugin supplies only the words and that ID, never an address.
 *
 * @since next
 */
final readonly class PanelPreview
{
    /** The longest title, in characters. @since next */
    public const int MOST_TITLE = 120;

    /** The longest description, in characters. @since next */
    public const int MOST_DESCRIPTION = 300;

    /**
     * @param  string  $title  1 to 120 characters of plain text
     * @param  string  $description  up to 300 characters of plain text
     * @param  string|null  $mediaId  the ID of a media item in the library, or null for no picture
     *
     * @throws InvalidArgumentException for a title or description outside those bounds
     *
     * @since next
     */
    public function __construct(
        public string $title,
        public string $description = '',
        public ?string $mediaId = null,
    ) {
        if ($title === '' || mb_strlen($title) > self::MOST_TITLE) {
            throw new InvalidArgumentException('A preview’s title must be 1 to 120 characters.');
        }

        if (mb_strlen($description) > self::MOST_DESCRIPTION) {
            throw new InvalidArgumentException('A preview’s description may be at most 300 characters.');
        }
    }
}
