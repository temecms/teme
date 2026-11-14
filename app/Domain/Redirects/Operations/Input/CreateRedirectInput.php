<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateRedirect needs: the address to redirect from, the status, and
 * where to send readers, an address typed, an item or a media file, none for
 * 410 and 451; a note; and who is asking.
 */
final readonly class CreateRedirectInput implements OperationInput
{
    public function __construct(
        public string $from,
        public int $status,
        public string $to = '',
        public ?string $contentId = null,
        public ?string $mediaId = null,
        public string $note = '',
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
