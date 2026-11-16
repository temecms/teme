<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use App\Domain\Extensions\RequestStatus;
use Teme\Contracts\Operations\OperationInput;

/**
 * What EndExtensionRequest needs: how the request ended, why, what the
 * installer found, and the codes of the plugins and themes the change
 * touched, so the page cache is purged only when the public sees it.
 */
final readonly class EndExtensionRequestInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $plugins  the codes of the plugins the change touched
     * @param  list<string>  $themes  the codes of the themes the change touched
     */
    public function __construct(
        public string $requestId,
        public RequestStatus $status,
        public ?string $error = null,
        public array $report = [],
        public array $plugins = [],
        public array $themes = [],
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
