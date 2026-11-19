<?php

/**
 * What CreateDesignPreview needs: the site-relative address to preview, such
 * as `/news/`, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CreateDesignPreviewInput implements OperationInput
{
    public function __construct(
        public string $path,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
