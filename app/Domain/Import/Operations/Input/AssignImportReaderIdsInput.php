<?php

/**
 * What `AssignImportReaderIds` needs: the site a reader read, the reader, the
 * items it read as their kind and source ID, and who reads them (null for the operator).
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AssignImportReaderIdsInput implements OperationInput
{
    /**
     * @param  list<array{kind: string, id: string}>  $items
     */
    public function __construct(
        public string $siteAddress,
        public string $reader,
        public array $items,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
