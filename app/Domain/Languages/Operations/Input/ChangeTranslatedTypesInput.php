<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Which content types and taxonomies are not translated (D25): their keys,
 * the languages' version the form was opened on, and who asks.
 */
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
