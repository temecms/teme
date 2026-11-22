<?php

/**
 * What `ImportTerm` needs to bring one WordPress category or tag in: its
 * taxonomy, name, slug, description and parent, the Teme ID of the term an
 * earlier run made when updating it (null to create), who is importing
 * (null for the operator), and the language a new term is made in, as
 * `language` stores it (null for the default; I-3446).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use App\Domain\Contents\Slugs;
use Teme\Contracts\Operations\OperationInput;

final readonly class ImportTermInput implements OperationInput
{
    /** The slug in its composed form, the form a request's address is looked up by. */
    public string $slug;

    public function __construct(
        public string $taxonomy,
        public string $name,
        string $slug,
        public ?string $description,
        public ?string $parentId,
        public ?string $termId,
        public ?string $actorId,
        public ?string $language = null,
    ) {
        $this->slug = Slugs::normal($slug);
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
