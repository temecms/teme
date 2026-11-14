<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

/**
 * The three places in every page that core owns for site-wide fragments
 * (I-228): the end of the head, the start of the body, after the skip link,
 * and the end of the body, before core's scripts.
 */
enum Placement: string
{
    case Head = 'head';
    case BodyStart = 'body-start';
    case BodyEnd = 'body-end';
}
