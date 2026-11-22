<?php

/**
 * What `CreatePerson` needs: the person's name, the address slug and
 * biography when given, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use App\Domain\Contents\Slugs;
use Teme\Contracts\Operations\OperationInput;

final readonly class CreatePersonInput implements OperationInput
{
    /** The slug in its composed form, the form a request's address is looked up by. */
    public ?string $slug;

    /**
     * @param  string|null  $slug  their author address's slug; made from the name when null
     */
    public function __construct(
        public ?string $actorId,
        public string $name,
        ?string $slug = null,
        public ?string $bio = null,
    ) {
        $this->slug = $slug === null ? null : Slugs::normal($slug);
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
