<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordMediaFiles needs: the source, the WordPress attachment, the file
 * keys its addresses make, the media item they lead to, and who is importing.
 */
final readonly class RecordMediaFilesInput implements OperationInput
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public string $sourceId,
        public int $attachmentId,
        public array $keys,
        public string $mediaId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
