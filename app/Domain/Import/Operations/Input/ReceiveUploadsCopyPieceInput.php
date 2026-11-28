<?php

/**
 * What `ReceiveUploadsCopyPiece` needs: the upload, the byte offset of this
 * piece, its bytes, and who is sending it (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReceiveUploadsCopyPieceInput implements OperationInput
{
    public function __construct(
        public string $uploadId,
        public int $offset,
        public string $bytes,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
