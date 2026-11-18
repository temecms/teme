<?php

/**
 * What ImportRedirect needs: the address to redirect from, where to send
 * readers (an address, an item or a media file, none for 410 and 451), the
 * status, the file an import's own redirect there sends readers to now, if it
 * is to move on from it, who is asking, and whether it may replace any
 * redirect the import wrote there.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ImportRedirectInput implements OperationInput
{
    public function __construct(
        public string $from,
        public string $to = '',
        public ?string $contentId = null,
        public ?string $mediaId = null,
        public int $status = 301,
        public ?string $replacing = null,
        public ?string $actorId = null,
        public bool $overImport = false,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
