<?php

/**
 * An export that started and failed: its cause, and the folder left behind
 * when the cleanup couldn't remove it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use RuntimeException;
use Throwable;

final class ExportFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $leftBehind, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
