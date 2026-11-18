<?php

/**
 * What SendToInbox needs: who receives the item, what kind it is, its words
 * and link, and who is sending.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Operations\Input;

use App\Domain\Inbox\InboxLink;
use Teme\Contracts\Operations\OperationInput;

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
