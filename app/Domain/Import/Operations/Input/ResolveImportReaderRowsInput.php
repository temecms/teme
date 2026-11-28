<?php

/**
 * What ResolveImportReaderRows needs: the file whose rows hold source IDs,
 * the site and reader those IDs were assigned for, and who asks.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ResolveImportReaderRowsInput implements OperationInput
{
    public function __construct(
        public string $fileId,
        public string $siteAddress,
        public string $reader,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
