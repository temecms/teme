<?php

/**
 * What `TranslateContent` needs: the item to translate, the language of the
 * new version as a tag, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class TranslateContentInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $language,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
