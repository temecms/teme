<?php

/**
 * What UpdateContentDetails needs: which item, the revision the editor
 * started from, who is asking, and only the details to change — a null
 * leaves that detail as it is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use App\Domain\Contents\Tree\TreePlace;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateContentDetailsInput implements OperationInput
{
    /**
     * @param  list<string>|null  $terms  the whole new list, in the editor's order
     * @param  array<string, mixed>|null  $fields  field key => new value, only the fields to change
     * @param  TreePlace|null  $place  where the item goes in its type's tree, or null to leave it (I-3363)
     */
    public function __construct(
        public string $contentId,
        public string $expectedRevisionId,
        public ?string $actorId,
        public ?string $title = null,
        public ?string $slug = null,
        public ?int $authorId = null,
        public ?array $terms = null,
        public ?string $publishedAt = null,
        public ?array $fields = null,
        public ?TreePlace $place = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
