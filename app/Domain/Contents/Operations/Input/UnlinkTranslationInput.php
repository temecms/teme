<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `UnlinkTranslation` needs: the item to take out of its translation
 * group, and who is asking.
 */
final readonly class UnlinkTranslationInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
