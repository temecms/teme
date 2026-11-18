<?php

/**
 * What EditRedirect needs: the redirect, then what CreateRedirectInput carries.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
