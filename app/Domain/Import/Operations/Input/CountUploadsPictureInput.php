<?php

/**
 * What `CountUploadsPicture` needs: the site, whether the picture came from
 * its uploads copy (or from the old site), and who is importing it (null for
 * the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CountUploadsPictureInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public bool $fromCopy,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
