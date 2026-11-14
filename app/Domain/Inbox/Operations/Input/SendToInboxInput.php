<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Operations\Input;

use App\Domain\Inbox\InboxLink;
use Teme\Contracts\Operations\OperationInput;

/**
 * What SendToInbox needs: who receives the item, what kind it is, its words
 * and link, and who is sending.
 */
final readonly class SendToInboxInput implements OperationInput
{
    public function __construct(
        public string $recipientId,
        public string $kind,
        public string $title,
        public ?string $text = null,
        public ?InboxLink $link = null,
        public ?string $actorId = null,
        public string $owner = 'core',
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
