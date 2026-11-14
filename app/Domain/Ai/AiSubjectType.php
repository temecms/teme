<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a task is asked about: an item, or a media item. Also what the
 * activity record calls its subject.
 */
enum AiSubjectType: string
{
    case Content = 'content';
    case Media = 'media';
}
