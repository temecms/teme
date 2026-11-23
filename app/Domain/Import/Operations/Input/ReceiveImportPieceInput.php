<?php

/**
 * What `ReceiveImportPiece` needs: the upload, the byte offset of this piece,
 * the piece’s bytes, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReceiveImportPieceInput implements OperationInput
{
    public function __construct(
        public string $fileId,
        public int $offset,
        public string $bytes,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
