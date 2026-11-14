<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use App\Domain\Contents\ImportedItem;
use Teme\Contracts\Operations\OperationInput;

/**
 * What UpdateImportedContent needs: the item an earlier run made, the record
 * the import kept of it (ImportedContents::fingerprint()), the item as
 * WordPress has it now, and who is importing (null for the operator).
 */
final readonly class UpdateImportedContentInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $written,
        public ImportedItem $item,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
