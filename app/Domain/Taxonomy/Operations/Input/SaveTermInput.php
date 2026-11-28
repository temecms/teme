<?php

/**
 * What CreateTerm and UpdateTerm need: the taxonomy, name, optional slug,
 * parent and description, who is asking, and — for an update — which term,
 * and whether to keep its parent or description where the edit sent none;
 * and for a new term, the language it is in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use App\Domain\Contents\Slugs;
use Teme\Contracts\Operations\OperationInput;

final readonly class SaveTermInput implements OperationInput
{
    /** The slug in its composed form, the form a request's address is looked up by. */
    public ?string $slug;

    /**
     * @param  string|null  $language  a new term's language, a site language's tag; null for the default (I-3360). An update keeps the term's own.
     * @param  string|null  $plugin  a plugin's code when it writes as the site
     */
    public function __construct(
        public string $taxonomy,
        public string $name,
        ?string $slug,
        public ?string $parentId,
        public ?string $description,
        public ?string $actorId,
        public ?string $termId = null,
        public bool $keepParent = false,
        public bool $keepDescription = false,
        public ?string $language = null,
        public ?string $plugin = null,
    ) {
        $this->slug = $slug === null ? null : Slugs::normal($slug);
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
