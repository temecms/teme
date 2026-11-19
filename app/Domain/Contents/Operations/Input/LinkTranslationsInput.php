<?php

/**
 * What `LinkTranslations` needs: the items to join into one translation
 * group, the first named first, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class LinkTranslationsInput implements OperationInput
{
    /**
     * @param  list<string>  $contentIds
     */
    public function __construct(
        public array $contentIds,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
