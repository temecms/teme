<?php

/**
 * An item's descriptive details, as the drawer submits them in full: title,
 * default alt text, caption, credit and focal point; and its licence, which
 * a caller that doesn't name it (null) leaves as it is, since most don't.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateMediaDetailsInput implements OperationInput
{
    public function __construct(
        public string $mediaId,
        public ?string $actorId,
        public string $title,
        public ?string $alt,
        public ?string $caption,
        public ?string $credit,
        public ?int $focalX = 50,
        public ?int $focalY = 50,
        public ?string $licence = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
