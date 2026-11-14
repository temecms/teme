<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What DeleteRedirects needs: the redirects, and who is asking.
 */
final readonly class DeleteRedirectsInput implements OperationInput
{
    /**
     * @param  list<string>  $redirectIds
     */
    public function __construct(
        public array $redirectIds,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
