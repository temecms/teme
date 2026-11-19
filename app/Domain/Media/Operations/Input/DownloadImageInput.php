<?php

/**
 * What DownloadImage needs: the image's address, as pasted, who is asking,
 * and the item open in the editor, which the new file records (I-553).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DownloadImageInput implements OperationInput
{
    public function __construct(
        public string $address,
        public ?string $actorId,
        public ?string $uploadedFor = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
