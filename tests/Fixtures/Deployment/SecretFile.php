<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Symfony\Component\Process\Process;

/**
 * Secret files for the tests, in the system's temporary folder, and php
 * artisan started as a container starts it, with variables added to the
 * test's own environment.
 */
final class SecretFile
{
    /**
     * A new file holding $contents exactly; its path.
     */
    public static function holding(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'teme-secret-');
        file_put_contents($path, $contents);

        return $path;
    }

    public static function remove(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * php artisan --version, run to its end.
     *
     * @param  array<string, string>  $variables
     */
    public static function artisanWith(array $variables): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), '--version'], base_path(), $variables, null, 60);
        $process->run();

        return $process;
    }
}
