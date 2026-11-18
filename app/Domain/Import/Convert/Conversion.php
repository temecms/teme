<?php

/**
 * What the converter made of one item's blocks (I-335): the document,
 * ready for DocumentNormaliser and DocumentValidator; the messages saying
 * what was left out or changed; and what the second pass still puts in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Import\Phases\ImportMessage;

final readonly class Conversion
{
    /**
     * @param  array{type: string, content: list<array<string, mixed>>}  $document
     * @param  list<ImportMessage>  $messages
     * @param  list<PendingReference>  $references
     * @param  list<string>  $pendingLinks  the paths links were left at for the second pass (I-333)
     */
    public function __construct(public array $document, public array $messages, public array $references, public array $pendingLinks = []) {}
}
