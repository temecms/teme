<?php

/**
 * Which content types and taxonomies are not translated (D25): their keys,
 * the languages' version the form was opened on, and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeTranslatedTypesInput implements OperationInput
{
    /**
     * @param  list<string>  $untranslatedTypes
     * @param  list<string>  $untranslatedTaxonomies
     */
    public function __construct(
        public array $untranslatedTypes,
        public array $untranslatedTaxonomies,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
