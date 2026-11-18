<?php

/**
 * What SaveDesignDraft needs: the values changed, group => key => value, only
 * the keys the person changed, and who is changing them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SaveDesignDraftInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $groups  such as ['design' => ['accent' => '#0b5394'], 'theme.good' => ['feed_layout' => 'grid']]
     */
    public function __construct(
        public array $groups,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
