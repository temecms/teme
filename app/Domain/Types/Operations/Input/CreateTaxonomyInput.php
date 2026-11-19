<?php

/**
 * What CreateTaxonomy needs: the taxonomy, the listed types that use it, and
 * who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use App\Domain\Types\TaxonomyDefinition;
use Teme\Contracts\Operations\OperationInput;

final readonly class CreateTaxonomyInput implements OperationInput
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public TaxonomyDefinition $taxonomy,
        public array $types = [],
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
