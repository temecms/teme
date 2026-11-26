<?php

/**
 * What DeleteRedirects needs: the redirects, and who is asking: a person, or
 * a plugin's code, which may remove only the redirects it added.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteRedirectsInput implements OperationInput
{
    /**
     * @param  list<string>  $redirectIds
     */
    public function __construct(
        public array $redirectIds,
        public ?string $actorId = null,
        public ?string $plugin = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
