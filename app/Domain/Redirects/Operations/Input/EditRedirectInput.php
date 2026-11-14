<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What EditRedirect needs: the redirect, then what CreateRedirectInput carries.
 */
final readonly class EditRedirectInput implements OperationInput
{
    public function __construct(
        public string $redirectId,
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
