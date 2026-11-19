<?php

/**
 * What ImportTypes needs: the decoded teme-types.json file, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ImportTypesInput implements OperationInput
{
    /**
     * @param  array<mixed>  $file
     */
    public function __construct(
        public array $file,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
