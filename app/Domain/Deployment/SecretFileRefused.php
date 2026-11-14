<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use RuntimeException;

/**
 * Why a secret named by a _FILE variable can't be read (I-375). Its
 * message names variables and the file's path, never a value.
 */
final class SecretFileRefused extends RuntimeException
{
    public static function bothSet(string $name): self
    {
        return new self(sprintf('%1$s and %1$s_FILE are both set. Set one or the other.', $name));
    }

    public static function unreadable(string $name, string $path): self
    {
        return new self(sprintf("%s_FILE names %s, which can't be read.", $name, $path));
    }
}
