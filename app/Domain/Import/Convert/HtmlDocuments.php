<?php

/**
 * Core's HtmlToDocument: HTML turned into the Tiptap document Teme stores by
 * the importer's own converter, run with no import, as the management API
 * runs it for an HTML body. Links and images stay at the addresses given.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\ManageApi\HtmlBodies;
use Teme\Contracts\Content\HtmlToDocument;

final readonly class HtmlDocuments implements HtmlToDocument
{
    public function __construct(private HtmlBodies $bodies) {}

    public function convert(string $html): array
    {
        return $this->bodies->convert($html)['document'];
    }
}
