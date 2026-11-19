<?php

/**
 * What RecordDirectoryIndex needs: the index whose signature was checked, or
 * the reason the directory was refused, when it was asked, and who asked:
 * none for the operator.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordDirectoryIndexInput implements OperationInput
{
    /**
     * @param  array<mixed>|null  $index  index.json as decoded, after its signature was checked
     */
    public function __construct(
        public ?array $index,
        public ?string $error,
        public CarbonImmutable $at,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
