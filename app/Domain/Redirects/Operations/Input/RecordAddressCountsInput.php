<?php

/**
 * What RecordAddressCounts needs: who is asking, null for the operator, and
 * how many addresses the 404 log keeps, and which it ignores.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use App\Domain\Redirects\NotFoundLog;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordAddressCountsInput implements OperationInput
{
    /**
     * @param  list<string>  $ignore  the patterns the 404 log ignores (I-874)
     */
    public function __construct(
        public ?string $actorId = null,
        public int $keep = NotFoundLog::KEEP,
        public array $ignore = NotFoundLog::IGNORED,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
