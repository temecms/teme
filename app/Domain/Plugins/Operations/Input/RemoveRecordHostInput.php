<?php

/**
 * What removing an agreed record host asks for: the plugin, the host, and the
 * person removing it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What removing an agreed record host asks for.
 *
 * @since next
 */
final readonly class RemoveRecordHostInput implements OperationInput
{
    /**
     * @param  string  $name  the plugin's name
     * @param  string  $host  the host agreed for records
     * @param  string|null  $actorId  the person removing it
     *
     * @since next
     */
    public function __construct(
        public string $name,
        public string $host,
        public ?string $actorId,
    ) {}

    /** @since next */
    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
