<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What SwitchRedirects needs: the redirects, whether to switch them on or
 * off, and who is asking.
 */
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
