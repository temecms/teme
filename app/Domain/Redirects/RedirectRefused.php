<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

use RuntimeException;

/**
 * Why a redirect can't be saved as asked: the field to show it under, and the words.
 */
final class RedirectRefused extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
