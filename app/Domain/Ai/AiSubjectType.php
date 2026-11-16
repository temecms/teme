<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a task is asked about: an item, or a media item. Also what the
 * activity record calls its subject, where none is a plugin's request about
 * no item.
 */
enum AiSubjectType: string
{
    case Content = 'content';
    case Media = 'media';
    case None = 'none';
}
