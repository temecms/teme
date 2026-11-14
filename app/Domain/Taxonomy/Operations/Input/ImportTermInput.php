<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `ImportTerm` needs to bring one WordPress category or tag in: its
 * taxonomy, name, slug, description and parent, the Teme ID of the term an
 * earlier run made when updating it (null to create), and who is importing
 * (null for the operator).
 */
final readonly class ImportTermInput implements OperationInput
{
    public function __construct(
        public string $taxonomy,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $parentId,
        public ?string $termId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
