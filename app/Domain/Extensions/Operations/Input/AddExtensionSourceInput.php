<?php

/**
 * What AddExtensionSource needs: the source's kind, a name for it, its https address, the username the
 * vendor gives if any, its key if any, and who adds it, or null for the operator.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class AddExtensionSourceInput implements OperationInput
{
    public function __construct(
        public string $kind,
        public string $label,
        public string $url,
        public ?string $username = null,
        #[SensitiveParameter] public ?string $credential = null,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * The input without its key: what is shown or logged.
     *
     * @return array{kind: string, label: string, url: string, username: ?string}
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'label' => $this->label, 'url' => $this->url, 'username' => $this->username];
    }
}
