<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

use App\Domain\Deployment\SecretFiles;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\Deployment\ImageFiles;

/**
 * What the tests of the operators' guide need beyond reading a page: the
 * pages that must exist, and the commands, variables, services and paths a
 * page names in code, set beside the recipe and the application that are
 * meant to make them true.
 */
final class OperatorGuide
{
    /** Every page, by its path under docs/guides/operators/. */
    public const array PAGES = [
        'README.md',
        'install.md',
        'configure.md',
        'database.md',
        'sizing.md',
        'proxies-and-https.md',
        'cloudflare.md',
        'platforms/dokploy.md',
        'platforms/coolify.md',
        'platforms/plain-vps.md',
        'updates.md',
        'backups.md',
        'restore-and-moving.md',
        'object-storage.md',
        'troubleshooting.md',
        'migration-runbook.md',
        'management-api.md',
        'extensions.md',
    ];

    /** How far a page is proved: by CI, by a run on real servers, by these tests alone, or not yet. */
    public const array PROOF = ['ci', 'run', 'tests', 'unproved', 'by hand on Dokploy v0.31.0, 2026-11-08'];

    /** Commands a later part builds. The part that builds one removes its line. */
    public const array ARRIVING = [];

    /** Variables of programs the pages mention that Teme itself does not read. */
    public const array OTHER_PROGRAMS = ['POSTGRES_USER', 'POSTGRES_PASSWORD', 'PGPASSWORD', 'COMPOSE_FILE', 'COMPOSE_PROJECT_NAME'];

    /** Settings the Dokploy template's compose file reads (temecms/temecms-for-dokploy) that Teme itself does not: the updater's own image. */
    public const array DOKPLOY_TEMPLATE = ['TEME_UPDATER_IMAGE'];

    /**
     * The pages as a dataset, each under its own name.
     *
     * @return array<string, array{GuideFile}>
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (self::PAGES as $name) {
            $pages[$name] = [self::page($name)];
        }

        return $pages;
    }

    public static function page(string $name): GuideFile
    {
        return new GuideFile("docs/guides/operators/{$name}");
    }

    public static function text(string $path): string
    {
        $text = @file_get_contents(GuideFile::root().'/'.$path);

        if ($text === false) {
            throw new RuntimeException("{$path} isn't there.");
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public static function recipe(): array
    {
        return Yaml::parse(self::text('docker/compose.yml'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function overlay(): array
    {
        return Yaml::parse(self::text('docker/compose.proxy.yml'));
    }

    /**
     * Every service of the recipe and of the proxy overlay.
     *
     * @return list<string>
     */
    public static function services(): array
    {
        return array_keys([...self::recipe()['services'], ...self::overlay()['services']]);
    }

    /**
     * Every profile the recipe and the overlay define.
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        $profiles = [];

        foreach ([...self::recipe()['services'], ...self::overlay()['services']] as $service) {
            array_push($profiles, ...($service['profiles'] ?? []));
        }

        return array_values(array_unique($profiles));
    }

    /**
     * The variables the recipe sets in a service's environment or asks
     * Compose to substitute.
     *
     * @return list<string>
     */
    public static function recipeVariables(): array
    {
        $names = [];
        preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', self::text('docker/compose.yml'), $found);
        array_push($names, ...$found[1]);

        foreach (self::recipe()['services'] as $service) {
            foreach ($service['environment'] ?? [] as $key => $value) {
                $names[] = is_string($key) ? $key : (string) strtok((string) $value, '=');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every variable a page may name: those the application reads, the
     * secrets' _FILE twins, the image's own, the recipe's, the overlay's and
     * the Dokploy template's.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        $names = [...ImageFiles::knownVariables(), ...SecretFiles::NAMES, ...self::recipeVariables(), ...self::OTHER_PROGRAMS, ...self::DOKPLOY_TEMPLATE];

        preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', self::text('docker/compose.proxy.yml'), $found);
        array_push($names, ...$found[1]);

        return array_values(array_unique($names));
    }

    /**
     * The variables a page names in code: an inline span that is one, or a
     * line of an env block.
     *
     * @return list<string>
     */
    public static function variables(GuideFile $page): array
    {
        $names = [];

        foreach ($page->codeSpans() as $span) {
            if (preg_match('/^([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)(?:=.*)?$/', $span, $found) === 1) {
                $names[] = $found[1];
            }
        }

        foreach ($page->fences() as $fence) {
            if ($fence['lang'] !== 'env') {
                continue;
            }

            foreach (explode("\n", $fence['body']) as $line) {
                if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/', $line, $found) === 1) {
                    $names[] = $found[1];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Each command a page names in code, with the long options given with
     * it: after php artisan on a line of a shell block or of a span, or a
     * span that begins with a teme: command.
     *
     * @return list<array{name: string, options: list<string>}>
     */
    public static function commands(GuideFile $page): array
    {
        $found = [];

        foreach (self::lines($page) as $line) {
            $line = (string) preg_split('/&&|\||;|#/', $line)[0];

            if (preg_match('/php artisan\s+([a-z][a-z0-9:-]*)(.*)$/', $line, $match) !== 1 && preg_match('/^\s*(teme:[a-z0-9:-]+)(.*)$/', $line, $match) !== 1) {
                continue;
            }

            preg_match_all('/\s--([a-z0-9-]+)/', ' '.$match[2], $options);
            $found[] = ['name' => $match[1], 'options' => $options[1]];
        }

        return $found;
    }

    /**
     * The services and profiles a page's docker compose lines name.
     *
     * @return array{services: list<string>, profiles: list<string>}
     */
    public static function composeTargets(GuideFile $page): array
    {
        $services = [];
        $profiles = [];
        $valueFlags = ['-f', '--file', '-p', '--project-name', '--profile', '--env-file', '-e', '--env', '-u', '--user', '-w', '--workdir', '--tail', '--since', '--until', '--name'];

        foreach (self::lines($page) as $line) {
            if (preg_match('/docker compose\b(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim((string) preg_split('/&&|\||;|#|>/', $match[1])[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $verb = null;
            $skip = false;

            foreach ($tokens as $position => $token) {
                if ($skip) {
                    $skip = false;

                    continue;
                }

                if (in_array($token, $valueFlags, true)) {
                    if ($token === '--profile' && isset($tokens[$position + 1])) {
                        $profiles[] = $tokens[$position + 1];
                    }

                    // After the verb, -f means follow, not a file.
                    $skip = $verb === null || ! in_array($token, ['-f'], true);

                    continue;
                }

                if (str_starts_with($token, '-')) {
                    continue;
                }

                if ($verb === null) {
                    $verb = $token;

                    continue;
                }

                if (in_array($verb, ['exec', 'run', 'logs', 'restart', 'stop', 'start', 'pull', 'up', 'ps', 'kill', 'rm'], true)) {
                    $services[] = $token;

                    if (in_array($verb, ['exec', 'run'], true)) {
                        break;
                    }
                }
            }
        }

        return ['services' => array_values(array_unique($services)), 'profiles' => array_values(array_unique($profiles))];
    }

    /**
     * Paths in code that begin in a folder of the repository.
     *
     * @return list<string>
     */
    public static function paths(GuideFile $page): array
    {
        return array_values(array_filter($page->codeSpans(), fn (string $span): bool => preg_match('#^(docker|config|app|docs|scripts|database|routes)/[\w./-]+$#', $span) === 1));
    }

    /**
     * The lines of a page that are commands to run: shell blocks, with a
     * line that ends in a backslash joined to the next, and spans.
     *
     * @return list<string>
     */
    private static function lines(GuideFile $page): array
    {
        $lines = $page->codeSpans();

        foreach ($page->fences() as $fence) {
            if (in_array($fence['lang'], ['sh', 'bash', 'shell', 'console', ''], true)) {
                array_push($lines, ...explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', $fence['body'])));
            }
        }

        return $lines;
    }
}
