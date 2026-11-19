<?php

/**
 * What SwitchRedirects needs: the redirects, whether to switch them on or
 * off, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SwitchRedirectsInput implements OperationInput
{
    /**
     * @param  list<string>  $redirectIds
     */
    public function __construct(
        public array $redirectIds,
        public bool $on,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
