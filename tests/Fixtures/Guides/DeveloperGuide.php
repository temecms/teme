<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Resolve;
use Teme\Contracts\Hooks\Transform;

/**
 * What the developer handbook tests need beyond reading a page: the pages
 * the README must list, and the commands, classes and example files a page
 * names in code.
 */
final class DeveloperGuide
{
    /** Every page, by its path under docs/guides/developers/, in README order. */
    public const array PAGES = [
        'README.md',
        'start/plugin-or-theme.md',
        'start/first-plugin.md',
        'start/first-theme.md',
        'concepts/manifest.md',
        'concepts/hooks.md',
        'concepts/operations.md',
        'testing.md',
        'for-agents.md',
        'plugins/content-write.md',
        'plugins/jobs.md',
        'plugins/public-routes.md',
        'plugins/import.md',
    ];

    public const array TYPES = ['tutorial', 'how-to', 'concept', 'reference', 'stub'];

    public const array AUDIENCES = ['plugin', 'theme', 'both'];

    /**
     * @return array<string, array{GuideFile}>
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (self::PAGES as $name) {
            if ($name === 'README.md') {
                continue;
            }

            $pages[$name] = [self::page($name)];
        }

        return $pages;
    }

    /**
     * Every content page including the README, for checks that apply to all.
     *
     * @return array<string, array{GuideFile}>
     */
    public static function allPages(): array
    {
        $pages = [];

        foreach (self::PAGES as $name) {
            $pages[$name] = [self::page($name)];
        }

        return $pages;
    }

    public static function page(string $name): GuideFile
    {
        return new GuideFile("docs/guides/developers/{$name}");
    }

    /**
     * Markdown files under docs/guides/developers/, excluding examples/.
     *
     * @return list<string>
     */
    public static function foundPages(): array
    {
        $folder = GuideFile::root().'/docs/guides/developers';
        $found = [];

        if (! is_dir($folder)) {
            return [];
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($folder) + 1));

            if (str_starts_with($relative, 'examples/')) {
                continue;
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }

    /**
     * Each command a page names in code, with its long options.
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
     * Hook short names, contracts basenames, and fully-qualified class names
     * named in backticks. Every PascalCase span is collected so a made-up
     * name fails the check instead of being ignored.
     *
     * @return list<string>
     */
    public static function codeNames(GuideFile $page): array
    {
        $names = [];

        foreach ($page->codeSpans() as $span) {
            if (str_contains($span, '\\')) {
                $names[] = $span;

                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z]+$/', $span) === 1) {
                $names[] = $span;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every class, interface and enum basename under teme-contracts.
     *
     * @return list<string>
     */
    public static function contractBasenames(): array
    {
        $root = GuideFile::root().'/packages/teme-contracts/src';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Teme\\Contracts\\'.str_replace(['/', '\\'], '\\', $relative);

            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $found[] = class_basename($class);
        }

        return array_values(array_unique($found));
    }

    /**
     * Property names from both plugin and theme manifest schemas.
     *
     * @return list<string>
     */
    public static function schemaKeys(): array
    {
        $keys = [];

        foreach (['teme-plugin.schema.json', 'teme-theme.schema.json'] as $file) {
            $schema = json_decode((string) file_get_contents(GuideFile::root().'/packages/teme-contracts/schema/'.$file), true, flags: JSON_THROW_ON_ERROR);
            array_push($keys, ...self::schemaPropertyNames($schema));
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return list<string>
     */
    private static function schemaPropertyNames(array $node): array
    {
        $keys = [];

        if (isset($node['properties']) && is_array($node['properties'])) {
            foreach ($node['properties'] as $name => $child) {
                $keys[] = (string) $name;

                if (is_array($child)) {
                    array_push($keys, ...self::schemaPropertyNames($child));
                }
            }
        }

        foreach (['items', 'additionalProperties', 'oneOf', 'anyOf', 'allOf', 'then', 'else', 'if'] as $branch) {
            if (! isset($node[$branch])) {
                continue;
            }

            if (is_array($node[$branch]) && array_is_list($node[$branch])) {
                foreach ($node[$branch] as $child) {
                    if (is_array($child)) {
                        array_push($keys, ...self::schemaPropertyNames($child));
                    }
                }
            } elseif (is_array($node[$branch])) {
                array_push($keys, ...self::schemaPropertyNames($node[$branch]));
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function hookBasenames(): array
    {
        $verbs = [Notification::class, Transform::class, Gate::class, Resolve::class, Collect::class];
        $root = GuideFile::root().'/packages/teme-contracts/src';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Teme\\Contracts\\'.str_replace(['/', '\\'], '\\', $relative);

            if (! class_exists($class) && ! interface_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isTrait()) {
                continue;
            }

            foreach ($verbs as $verb) {
                if ($reflection->implementsInterface($verb) || $class === $verb) {
                    $found[] = class_basename($class);

                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private static function lines(GuideFile $page): array
    {
        $lines = [];

        foreach ($page->codeSpans() as $span) {
            $lines[] = $span;
        }

        foreach ($page->fences() as $fence) {
            foreach (explode("\n", $fence['body']) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
