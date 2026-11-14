<?php

declare(strict_types=1);

/*
 * Documentation decisions I-132 and I-133, enforced.
 * docs/architecture/coding-standards.md.
 */

/**
 * @return list<string>
 */
function documentationClassesIn(string $directory, string $namespace): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
        $classes[] = $namespace.'\\'.str_replace(['/', '\\'], '\\', $relative);
    }

    sort($classes);

    return $classes;
}

/**
 * The prose of a docblock, before its first tag.
 */
function documentationSummary(string|false $docComment): string
{
    if ($docComment === false) {
        return '';
    }

    $prose = [];

    foreach (preg_split('/\R/', $docComment) ?: [] as $line) {
        $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)#', '', trim($line)));
        $line = trim((string) preg_replace('#\*/$#', '', $line));

        if (str_starts_with($line, '@')) {
            break;
        }

        if ($line !== '') {
            $prose[] = $line;
        }
    }

    return implode(' ', $prose);
}

it('gives every class in app/ a docblock that says what it is for', function (): void {
    $undocumented = [];

    foreach (documentationClassesIn(dirname(__DIR__, 2).'/app', 'App') as $class) {
        if (documentationSummary((new ReflectionClass($class))->getDocComment()) === '') {
            $undocumented[] = $class;
        }
    }

    expect($undocumented)->toBe([]);
});

it('says what every public query method returns', function (): void {
    $undocumented = [];

    foreach (documentationClassesIn(dirname(__DIR__, 2).'/app/Domain', 'App\\Domain') as $class) {
        if (! str_contains($class, '\\Queries\\')) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->isConstructor()) {
                continue;
            }

            if (documentationSummary($method->getDocComment()) === '') {
                $undocumented[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($undocumented)->toBe([]);
});

it('cites only documents that exist', function (): void {
    $root = dirname(__DIR__, 2);
    $missing = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'packages', 'resources/js', 'routes', 'tests'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php', 'ts', 'tsx', 'neon'], true)) {
                continue;
            }

            preg_match_all('#(?<![\w/.-])((?:docs|\.ai)/[\w./-]+\.md)#', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $cited) {
                // Working documents outside the published tree: comments may still cite them.
                if (preg_match('#^docs/(ideas\.md|backlog\.md|studies/|superpowers/plans/|demos/|prototypes/)#', $cited) === 1) {
                    continue;
                }

                if (! is_file($root.'/'.$cited)) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1).' cites '.$cited;
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
