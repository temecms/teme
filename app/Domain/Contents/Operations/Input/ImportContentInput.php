<?php

/**
 * What ImportContent needs: the new item's ID, the item as the import decided
 * it, and who is importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use App\Domain\Contents\ImportedItem;
use Teme\Contracts\Operations\OperationInput;

final readonly class ImportContentInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public ImportedItem $item,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
