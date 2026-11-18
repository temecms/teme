<?php

/**
 * What UpdateImportedMedia needs: the item, what an import last wrote to it,
 * WordPress's title, alt text and caption now, WordPress's new file if it
 * changed, where it came from, and who is importing it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateImportedMediaInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $written  the fingerprint ImportMedia returned
     */
    public function __construct(
        public string $mediaId,
        public array $written,
        public string $title,
        public ?string $alt,
        public ?string $caption,
        public ?string $path,
        public ?string $filename,
        public ?string $sourceUrl,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
