<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Closure;

/**
 * Sets variables where Laravel's env() reads them, for one run, then puts
 * each back as it was, in $_SERVER, $_ENV and the process environment. Name
 * every variable the run may set, a secret file's too, so nothing outlives
 * the test and the developer's .env decides nothing.
 */
final class Environment
{
    /**
     * @template T
     *
     * @param  array<string, string|null>  $variables  null unsets one
     * @param  Closure(): T  $run
     *
     * @return T
     */
    public static function with(array $variables, Closure $run): mixed
    {
        $saved = [];

        foreach ($variables as $name => $value) {
            $saved[$name] = [
                'server' => array_key_exists($name, $_SERVER) ? [$_SERVER[$name]] : [],
                'env' => array_key_exists($name, $_ENV) ? [$_ENV[$name]] : [],
                'process' => getenv($name),
            ];

            self::put($name, $value);
        }

        try {
            return $run();
        } finally {
            foreach ($saved as $name => $was) {
                unset($_SERVER[$name], $_ENV[$name]);

                if ($was['server'] !== []) {
                    $_SERVER[$name] = $was['server'][0];
                }

                if ($was['env'] !== []) {
                    $_ENV[$name] = $was['env'][0];
                }

                putenv($was['process'] === false ? $name : $name.'='.$was['process']);
            }
        }
    }

    private static function put(string $name, ?string $value): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($value !== null) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }
}
