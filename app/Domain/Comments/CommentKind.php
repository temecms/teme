<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * What a comment is: a comment, or a pingback or trackback kept from a
 * WordPress import. Pings are never accepted (I-277).
 */
enum CommentKind: string
{
    case Comment = 'comment';
    case Pingback = 'pingback';
    case Trackback = 'trackback';
}
