<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportMedia needs: the downloaded file on local disk, its name, where it
 * came from, the title, alt text and caption the old site gave it, who
 * uploaded it and when, and who is importing it.
 */
final readonly class ImportMediaInput implements OperationInput
{
    public function __construct(
        public string $path,
        public string $filename,
        public ?string $sourceUrl,
        public string $title,
        public ?string $alt,
        public ?string $caption,
        public ?string $uploadedBy,
        public ?CarbonImmutable $uploadedAt,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
