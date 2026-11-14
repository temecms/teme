<?php

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * One of the site's other languages, changed: its new address prefix,
 * whether readers are shown it, the languages' version the form was opened
 * on, and who asks.
 */
final readonly class ChangeSiteLanguageInput implements OperationInput
{
    public function __construct(
        public string $tag,
        public string $prefix,
        public bool $shown,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
