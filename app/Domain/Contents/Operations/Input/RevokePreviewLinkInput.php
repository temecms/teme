<?php

/**
 * A preview link to revoke, and, from a URL that names one, the article it
 * must belong to.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RevokePreviewLinkInput implements OperationInput
{
    public function __construct(
        public string $linkId,
        public ?string $actorId,
        public ?string $contentId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
