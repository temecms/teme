<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What StartJob needs: which kind of work, its label, its total if known, the run it belongs to, who is starting it and which part owns it.
 */
final readonly class StartJobInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $payload  JSON-ready data kept on the tracked job (I-3445)
     */
    public function __construct(
        public string $kind,
        public string $label,
        public ?int $total = null,
        public ?string $runType = null,
        public ?string $runId = null,
        public ?string $actorId = null,
        public string $owner = 'core',
        public array $payload = [],
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
