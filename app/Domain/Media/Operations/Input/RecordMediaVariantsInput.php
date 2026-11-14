<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A processing result for one file version of an image: the variants made
 * with a given set, or the error that stopped them.
 */
final readonly class RecordMediaVariantsInput implements OperationInput
{
    /**
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>|null  $variants
     * @param  ?string  $usedBy  the item whose use of the image lets the actor record its variants (I-855)
     */
    public function __construct(
        public string $mediaId,
        public int $fileVersion,
        public int $variantSet,
        public ?array $variants,
        public ?string $error,
        public ?string $actorId,
        public ?string $usedBy = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
