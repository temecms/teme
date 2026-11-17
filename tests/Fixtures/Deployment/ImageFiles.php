<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use App\Domain\Deployment\SecretFiles;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the files the image and the recipe are made of, for tests that
 * can't run Docker: the Dockerfile as instructions, the recipe and the
 * workflow as data, nginx's locations as blocks.
 */
final class ImageFiles
{
    /** The recipe's services that run Teme's image. */
    public const array TEME_ROLES = ['deploy', 'web', 'worker', 'worker-long', 'scheduler'];

    /** Every nginx file the image takes from docker/. */
    public const array NGINX_FILES = [
        'docker/nginx/conf.d/teme-static.conf',
        'docker/nginx/conf.d/teme-edge.conf',
        'docker/nginx/site-opts.d/http.conf.template',
        'docker/nginx/server-opts.d/remoteip.conf',
        'docker/nginx/server-opts.d/security.conf',
        'docker/nginx/server-opts.d/performance.conf',
        'docker/nginx/server-opts.d/teme-backups.conf',
    ];

    /** Variables Laravel itself reads, outside config/. */
    public const array LARAVEL_VARIABLES = ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'];

    /** Variables libpq, under pdo_pgsql, reads as a connection's defaults. */
    public const array LIBPQ_VARIABLES = ['PGCONNECT_TIMEOUT'];

    /** serversideup/php v4.5.1's own variables that the image or the recipe sets. */
    public const array IMAGE_VARIABLES = [
        'AUTORUN_ENABLED',
        'AUTORUN_LARAVEL_MIGRATION',
        'HEALTHCHECK_PATH',
        'PORT',
        'SHOW_WELCOME_MESSAGE',
        'PHP_OPCACHE_ENABLE',
        'PHP_OPCACHE_VALIDATE_TIMESTAMPS',
        'NGINX_CLIENT_MAX_BODY_SIZE',
        'NGINX_FASTCGI_BUFFER_SIZE',
        'NGINX_FASTCGI_BUFFERS',
        'PHP_UPLOAD_MAX_FILE_SIZE',
        'PHP_POST_MAX_SIZE',
        'PHP_FPM_PM_MAX_CHILDREN',
    ];

    /**
     * Variables the recipe names that config/ reads only once main is
     * merged in (the spare database, I-2406). Emptied the day it does.
     */
    public const array ARRIVING_WITH_MAIN = [];

    public static function text(string $path): string
    {
        $text = @file_get_contents(base_path($path));

        if ($text === false) {
            throw new RuntimeException("{$path} isn't there.");
        }

        return $text;
    }

    /**
     * The Dockerfile's instructions, one string each: continued lines
     * joined, blank lines and comments left out.
     *
     * @return list<string>
     */
    public static function instructions(): array
    {
        $instructions = [];
        $current = '';

        foreach (preg_split('/\R/', self::text('Dockerfile')) ?: [] as $line) {
            $line = trim($line);

            if ($current === '' && ($line === '' || str_starts_with($line, '#'))) {
                continue;
            }

            if (str_ends_with($line, '\\')) {
                $current .= substr($line, 0, -1).' ';

                continue;
            }

            $instructions[] = $current.$line;
            $current = '';
        }

        return $instructions;
    }

    /**
     * One stage's instructions, from its FROM to the next.
     *
     * @return list<string>
     */
    public static function stage(string $name): array
    {
        $stage = [];
        $inside = false;

        foreach (self::instructions() as $instruction) {
            if (str_starts_with($instruction, 'FROM ')) {
                $inside = str_ends_with($instruction, " AS {$name}");
            }

            if ($inside) {
                $stage[] = $instruction;
            }
        }

        return $stage;
    }

    /**
     * The runtime stage's ENV, by name.
     *
     * @return array<string, string>
     */
    public static function runtimeEnvironment(): array
    {
        $environment = [];

        foreach (self::stage('runtime') as $instruction) {
            if (! str_starts_with($instruction, 'ENV ')) {
                continue;
            }

            preg_match_all('/([A-Z][A-Z0-9_]*)=("[^"]*"|\S+)/', substr($instruction, 4), $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $environment[$pair[1]] = trim($pair[2], '"');
            }
        }

        return $environment;
    }

    /**
     * @return array<string, mixed>
     */
    public static function compose(): array
    {
        return Yaml::parse(self::text('docker/compose.yml'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function workflow(): array
    {
        return Yaml::parse(self::text('.github/workflows/image.yml'));
    }

    /**
     * Each location in an nginx file that opens at the line's start: what it
     * matches, and its body.
     *
     * @return list<array{match: string, body: string}>
     */
    public static function locations(string $path): array
    {
        preg_match_all('/^location\s+(.+?)\s*\{\n(.*?)^\}/ms', self::text($path), $found, PREG_SET_ORDER);

        return array_map(fn (array $one): array => ['match' => $one[1], 'body' => $one[2]], $found);
    }

    /**
     * A file's lines that are neither blank nor comments, trimmed.
     *
     * @return list<string>
     */
    public static function directives(string $path): array
    {
        $lines = array_map(trim(...), preg_split('/\R/', self::text($path)) ?: []);

        return array_values(array_filter($lines, fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    /**
     * Every variable config/ reads with env(), each secret's _FILE, and the
     * variables Laravel and the image read themselves.
     *
     * @return list<string>
     */
    public static function knownVariables(): array
    {
        $names = [...self::LARAVEL_VARIABLES, ...self::LIBPQ_VARIABLES, ...self::IMAGE_VARIABLES];

        foreach (glob(base_path('config/*.php')) ?: [] as $file) {
            preg_match_all("/\\benv\\(\\s*'([A-Z][A-Z0-9_]*)'/", (string) file_get_contents($file), $found);
            array_push($names, ...$found[1]);
        }

        foreach (SecretFiles::NAMES as $name) {
            $names[] = $name.'_FILE';
        }

        return array_values(array_unique($names));
    }

    /** The seconds in a Compose duration such as 30s, 6m or 1h. */
    public static function seconds(string $duration): int
    {
        if (preg_match('/^(\d+)(s|m|h)$/', $duration, $found) !== 1) {
            throw new RuntimeException("{$duration} isn't a duration this test reads.");
        }

        return (int) $found[1] * ['s' => 1, 'm' => 60, 'h' => 3600][$found[2]];
    }
}
