<?php

/**
 * One of the site's other languages to remove, the languages' version the
 * form was opened on, and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
