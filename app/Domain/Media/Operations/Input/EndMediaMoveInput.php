<?php

/**
 * What EndMediaMove needs: the run, how it ended (finished, or stopped to
 * be resumed) and what it says.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class EndMediaMoveInput implements OperationInput
{
    /**
     * @param  string  $state  finished or stopped
     */
    public function __construct(
        public string $runId,
        public string $state,
        public ?string $message,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
