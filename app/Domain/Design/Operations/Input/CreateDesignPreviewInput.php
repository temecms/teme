<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateDesignPreview needs: the site-relative address to preview, such
 * as `/news/`, and who is asking.
 */
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
