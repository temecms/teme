<?php

/**
 * What UploadExtension needs: the archive's file name and its bytes, and who uploads it, or null for the operator.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UploadExtensionInput implements OperationInput
{
    public function __construct(
        public string $fileName,
        public string $bytes,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * The input without the archive's bytes: what is shown or logged.
     *
     * @return array{fileName: string, size: int}
     */
    public function __debugInfo(): array
    {
        return ['fileName' => $this->fileName, 'size' => strlen($this->bytes)];
    }
}
