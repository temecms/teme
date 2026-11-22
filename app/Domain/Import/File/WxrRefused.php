<?php

/**
 * Why an uploaded file can't be read as a WordPress export file, as the one
 * plain sentence shown beside it. Its message is its sentence; it never
 * carries the file's own text.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use RuntimeException;

final class WxrRefused extends RuntimeException
{
    private function __construct(public readonly string $sentence)
    {
        parent::__construct($sentence);
    }

    /**
     * A file with a document type declaration, which an export never has:
     * refused whole, so no entity or outside document is ever read.
     */
    public static function documentType(): self
    {
        return new self(__('This file has a document type declaration, which an export file never has. Teme doesn’t read it.'));
    }

    /**
     * A file that isn't WordPress's export: another feed, other XML, or XML
     * that breaks off partway.
     */
    public static function notAnExport(): self
    {
        return new self(__('That isn’t a WordPress export file. Teme reads the .xml file from WordPress’s Tools › Export. Choose that file and try again.'));
    }
}
