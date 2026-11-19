<?php

declare(strict_types=1);

/*
 * Every PHP file says what it is, which package it belongs to, the release
 * it arrived in and its licence, in a fixed header, enforced.
 * docs/architecture/coding-standards.md.
 */

/**
 * The PHP files that carry a header, relative to the repository's root.
 *
 * @return list<string>
 */
function fileHeaderCoveredFiles(string $root): array
{
    $directories = ['app', 'config', 'database', 'packages/teme-contracts/src', 'packages/teme-testing/src', 'routes'];

    foreach (['plugins/*/src', 'themes/*/src'] as $glob) {
        foreach (glob($root.'/'.$glob, GLOB_ONLYDIR) ?: [] as $directory) {
            $directories[] = substr($directory, strlen($root) + 1);
        }
    }

    $files = [];

    foreach ($directories as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);

            if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Why a file's opening is not a valid header, or null when it is.
 */
function fileHeaderProblem(string $source): ?string
{
    $opening = '#\A<\?php\n\n/\*\*\n((?: \*(?: [^\n]*)?\n)+?) \*/\n\ndeclare\(strict_types=1\);\n#';

    if (preg_match($opening, $source, $match) !== 1) {
        return 'no header docblock between <?php and declare(strict_types=1)';
    }

    $lines = array_map(fn (string $line): string => (string) preg_replace('/^ \* ?/', '', $line), explode("\n", rtrim($match[1], "\n")));
    $firstTag = null;

    foreach ($lines as $index => $line) {
        if (str_starts_with($line, '@')) {
            $firstTag = $index;

            break;
        }
    }

    if ($firstTag === null || trim(implode('', array_slice($lines, 0, $firstTag))) === '') {
        return 'no sentence saying what the file is for';
    }

    $tags = array_slice($lines, $firstTag);
    $expected = [
        '/^@package \S+$/',
        '/^@since   (?:next|\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/',
        '/^@license \S.*$/',
    ];

    if (count($tags) !== count($expected)) {
        return 'tags other than @package, @since and @license';
    }

    foreach ($expected as $index => $pattern) {
        if (preg_match($pattern, $tags[$index]) !== 1) {
            return 'tags not @package, @since and @license in that order: '.$tags[$index];
        }
    }

    return null;
}

it('gives every covered PHP file its header: what it is for, @package, @since and @license, in that order', function (): void {
    $root = dirname(__DIR__, 2);
    $wrong = [];

    foreach (fileHeaderCoveredFiles($root) as $path) {
        $problem = fileHeaderProblem((string) file_get_contents($root.'/'.$path));

        if ($problem !== null) {
            $wrong[] = $path.': '.$problem;
        }
    }

    expect($wrong)->toBe([]);
});

it('reads a header the way the rule describes it', function (): void {
    $valid = "<?php\n\n/**\n * Says what the file is for.\n *\n * @package Teme\n * @since   1.2.0\n * @license MIT\n */\n\ndeclare(strict_types=1);\n";

    expect(fileHeaderProblem($valid))->toBeNull()
        ->and(fileHeaderProblem(str_replace('1.2.0', 'next', $valid)))->toBeNull()
        ->and(fileHeaderProblem(str_replace('1.2.0', '1.2.0-rc.1', $valid)))->toBeNull()
        ->and(fileHeaderProblem("<?php\n\ndeclare(strict_types=1);\n"))->not->toBeNull()
        ->and(fileHeaderProblem(str_replace(" * Says what the file is for.\n *\n", '', $valid)))->not->toBeNull()
        ->and(fileHeaderProblem(str_replace("@since   1.2.0\n * @license MIT", "@license MIT\n * @since   1.2.0", $valid)))->not->toBeNull()
        ->and(fileHeaderProblem(str_replace('@since   1.2.0', '@since   soon', $valid)))->not->toBeNull()
        ->and(fileHeaderProblem(str_replace(" * @license MIT\n", " * @license MIT\n * @author Someone\n", $valid)))->not->toBeNull();
});

it('leaves no @since next in a commit that is a release tag', function (): void {
    $root = dirname(__DIR__, 2);
    $tag = trim((string) shell_exec('git -C '.escapeshellarg($root).' describe --tags --exact-match --match "v*" HEAD 2>/dev/null'));

    if ($tag === '') {
        $this->markTestSkipped('HEAD is not a release tag.');
    }

    $left = array_values(array_filter(
        fileHeaderCoveredFiles($root),
        fn (string $path): bool => ! str_starts_with($path, 'plugins/') && ! str_starts_with($path, 'themes/')
            && preg_match('/@since\s+next\b/', (string) file_get_contents($root.'/'.$path)) === 1,
    ));

    expect($left)->toBe([]);
});
