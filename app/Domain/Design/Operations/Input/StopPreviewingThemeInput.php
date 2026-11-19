<?php

/**
 * What StopPreviewingTheme needs: the theme being previewed, by its code,
 * and who stops previewing it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class StopPreviewingThemeInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
