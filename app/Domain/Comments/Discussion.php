<?php

/**
 * Whether an item takes comments (I-274): Open, its comments and the form
 * shown; Closed, its comments shown and no form; Hidden, neither. Drupal's
 * three values; WordPress has the first two. It is the item's
 * `comment_status`.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

enum Discussion: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Hidden = 'hidden';

    /** The state's name as the admin shows it. */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Closed => __('Closed'),
            self::Hidden => __('Hidden'),
        };
    }
}
