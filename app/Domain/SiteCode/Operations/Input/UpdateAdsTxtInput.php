<?php

/**
 * What UpdateAdsTxt needs: the ads.txt text or a redirect to another host's
 * file, the Site code settings group's version the form was opened on, and
 * who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateAdsTxtInput implements OperationInput
{
    public function __construct(
        public string $adsTxt,
        public int $expectedVersion,
        public ?string $actorId,
        public string $mode = 'text',
        public string $redirectUrl = '',
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
