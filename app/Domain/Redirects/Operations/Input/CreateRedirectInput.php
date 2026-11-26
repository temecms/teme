<?php

/**
 * What CreateRedirect needs: the address to redirect from, the status, and
 * where to send readers, an address typed, an item or a media file, none for
 * 410 and 451; a note; and who is asking: a person, or a plugin's code when
 * a plugin adds it as the site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
        public ?string $plugin = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
