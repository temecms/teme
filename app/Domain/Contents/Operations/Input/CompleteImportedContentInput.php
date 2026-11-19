<?php

/**
 * What CompleteImportedContent needs: the item, the import's record of it, the
 * finished document and fields, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CompleteImportedContentInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public string $contentId,
        public string $written,
        public array $document,
        public array $fields,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
