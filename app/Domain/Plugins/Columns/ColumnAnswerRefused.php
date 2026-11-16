<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

use RuntimeException;

/**
 * A list-column answer that held something of the wrong shape for its call.
 */
final class ColumnAnswerRefused extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
