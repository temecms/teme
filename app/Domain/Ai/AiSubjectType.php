<?php

/**
 * What a task is asked about: an item, or a media item. Also what the
 * activity record calls its subject, where none is a plugin's request about
 * no item.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

enum AiSubjectType: string
{
    case Content = 'content';
    case Media = 'media';
    case None = 'none';
}
