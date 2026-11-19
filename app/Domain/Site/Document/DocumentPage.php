<?php

/**
 * The page the document shell is drawing, as head and placement contributors
 * see it: what kind of page it is, and its view model, the template's
 * `$page`; both null outside a page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use App\Domain\Themes\ViewIntent;

final readonly class DocumentPage
{
    public function __construct(public ?ViewIntent $intent, public ?object $model) {}
}
