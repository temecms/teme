<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * One of the site's other languages to remove, the languages' version the
 * form was opened on, and who asks.
 */
final readonly class RemoveSiteLanguageInput implements OperationInput
{
    public function __construct(
        public string $tag,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
