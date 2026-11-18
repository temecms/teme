<?php

/**
 * A preview link to make: which revision of which article it shows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CreatePreviewLinkInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
