<?php

/**
 * What `RefusePluginScript` needs: the plugin's script and who is deciding.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RefusePluginScriptInput implements OperationInput
{
    public function __construct(
        public string $scriptId,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
