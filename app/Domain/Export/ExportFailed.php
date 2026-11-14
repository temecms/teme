<?php

declare(strict_types=1);

namespace App\Domain\Export;

use RuntimeException;
use Throwable;

/**
 * An export that started and failed: its cause, and the folder left behind
 * when the cleanup couldn't remove it.
 */
final class ExportFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $leftBehind, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
