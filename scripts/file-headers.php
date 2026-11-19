<?php

declare(strict_types=1);

/*
 * Every PHP file's header, and the @since tags on public members
 * (docs/architecture/coding-standards.md). Runs on plain PHP, 8.3 or later,
 * with nothing installed, from the repository's root.
 *
 *   php scripts/file-headers.php apply [--map=since.json] [--describe=sentences.json] [path ...]
 *       Gives every covered file that has none its header. @since is the
 *       first release tag whose tree held the file, following renames, or
 *       `next` when no release has it yet. A file it cannot describe is
 *       listed, for a header written by hand, or a sentence in --describe's
 *       JSON object of path => sentence.
 *   php scripts/file-headers.php members [--map=since.json] [path ...]
 *       Adds @since to the public methods and constants of app/ that arrived
 *       after their class's own release.
 *   php scripts/file-headers.php map
 *       Prints, as JSON, the release each covered file and public member
 *       first appeared in, read from this clone's history and tags, for
 *       `--map` in a clone whose history starts later.
 *   php scripts/file-headers.php check
 *       Lists covered files whose header is missing or out of order.
 *   php scripts/file-headers.php release <version> [--path=plugins/name]
 *       Replaces `@since next` with the version being released.
 *   php scripts/file-headers.php check-release [--path=plugins/name]
 *       Fails when `@since next` is left, as a release tag must never hold it.
 */

const CORE_DIRECTORIES = ['app', 'config', 'database', 'packages/teme-contracts/src', 'packages/teme-testing/src', 'routes'];

const EXTENSION_GLOBS = ['plugins/*/src', 'themes/*/src'];

const SINCE_PATTERN = '(?:next|\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)';

/**
 * Directories whose class docblocks are published documentation, read by
 * the contracts, hooks and theme reference generators and by editors: their
 * prose stays on the class, and the header carries its first sentence.
 */
const KEEP_CLASS_PROSE = ['packages/teme-contracts/src/', 'app/Domain/Site/ViewModels/', 'app/View/Components/Teme/'];

$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? '';
$options = [];
$paths = [];

foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        $paths[] = rtrim($argument, '/');
    }
}

exit(match ($command) {
    'apply' => apply($paths, $options),
    'members' => members($paths, $options),
    'map' => printMap(),
    'check' => check($paths),
    'release' => release($paths[0] ?? '', $options['path'] ?? null),
    'check-release' => checkRelease($options['path'] ?? null),
    default => usage(),
});

function usage(): int
{
    fwrite(STDERR, "Usage: php scripts/file-headers.php apply|members|map|check|release <version>|check-release [--map=file] [--path=dir] [path ...]\n");

    return 2;
}

/**
 * The covered PHP files, relative to the root, sorted.
 *
 * @param  list<string>  $only
 *
 * @return list<string>
 */
function coveredFiles(array $only = []): array
{
    $directories = CORE_DIRECTORIES;

    foreach (EXTENSION_GLOBS as $glob) {
        foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $directory) {
            $directories[] = $directory;
        }
    }

    $files = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }

            if ($only !== [] && ! array_filter($only, fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix.'/'))) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * The extension directory (plugins/x or themes/x) a path belongs to, or null for Teme's own.
 */
function extensionOf(string $path): ?string
{
    return preg_match('#^((?:plugins|themes)/[^/]+)/#', $path, $match) === 1 ? $match[1] : null;
}

/**
 * The @package and @license a file's header names.
 *
 * @return array{package: string, license: string}
 */
function packageOf(string $path): array
{
    $extension = extensionOf($path);

    if ($extension === null) {
        return ['package' => 'Teme', 'license' => 'MIT'];
    }

    $composer = json_decode((string) @file_get_contents($extension.'/composer.json'), true);
    $license = $composer['license'] ?? 'MIT';

    return [
        'package' => is_string($composer['name'] ?? null) ? $composer['name'] : 'teme/'.basename($extension),
        'license' => is_array($license) ? implode(' OR ', $license) : (string) $license,
    ];
}

/**
 * The header's parts when the file has a valid one, or null.
 *
 * @return array{prose: string, package: string, since: string, license: string}|null
 */
function headerOf(string $source): ?array
{
    $pattern = '#\A<\?php\n\n/\*\*\n((?: \*(?: [^\n]*)?\n)+?) \*/\n\ndeclare\(strict_types=1\);#';

    if (preg_match($pattern, $source, $match) !== 1) {
        return null;
    }

    $lines = array_map(fn (string $line): string => (string) preg_replace('/^ \* ?/', '', $line), explode("\n", rtrim($match[1], "\n")));
    $prose = [];
    $tags = [];

    foreach ($lines as $line) {
        if ($tags !== [] || str_starts_with($line, '@')) {
            $tags[] = $line;
        } else {
            $prose[] = $line;
        }
    }

    $tagPattern = ['/^@package\s+(\S+)$/', '/^@since\s+('.SINCE_PATTERN.')$/', '/^@license\s+(.+)$/'];
    $values = [];

    if (count($tags) !== 3) {
        return null;
    }

    foreach ($tagPattern as $index => $pattern) {
        if (preg_match($pattern, $tags[$index], $found) !== 1) {
            return null;
        }

        $values[] = $found[1];
    }

    $prose = trim(implode(' ', array_map('trim', $prose)));

    if ($prose === '') {
        return null;
    }

    return ['prose' => $prose, 'package' => $values[0], 'since' => $values[1], 'license' => $values[2]];
}

/**
 * @param  list<string>  $only
 */
function check(array $only): int
{
    $wrong = [];

    foreach (coveredFiles($only) as $path) {
        if (headerOf((string) file_get_contents($path)) === null) {
            $wrong[] = $path;
        }
    }

    foreach ($wrong as $path) {
        fwrite(STDERR, "{$path} has no valid header.\n");
    }

    return $wrong === [] ? 0 : 1;
}

/**
 * Directories a release step stamps: Teme's own, or one extension's.
 *
 * @return list<string>
 */
function releasePaths(?string $extension): array
{
    if ($extension !== null) {
        return [rtrim($extension, '/')];
    }

    return CORE_DIRECTORIES;
}

/**
 * Every PHP file under the release paths that still says `@since next`.
 *
 * @return list<string>
 */
function filesWithNext(?string $extension): array
{
    $found = [];

    foreach (releasePaths($extension) as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, '.php') && preg_match('/@since\s+next\b/', (string) file_get_contents($path)) === 1) {
                $found[] = $path;
            }
        }
    }

    sort($found);

    return $found;
}

function release(string $version, ?string $extension): int
{
    if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version) !== 1) {
        fwrite(STDERR, "Give the version being released, such as 1.2.0.\n");

        return 2;
    }

    $files = filesWithNext($extension);

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        file_put_contents($path, (string) preg_replace('/@since(\s+)next\b/', '@since${1}'.$version, $source));
    }

    fwrite(STDOUT, count($files)." files now say @since {$version}.\n");

    return 0;
}

function checkRelease(?string $extension): int
{
    $files = filesWithNext($extension);

    foreach ($files as $path) {
        fwrite(STDERR, "{$path} still says @since next. Run `php scripts/file-headers.php release <version>` before tagging.\n");
    }

    return $files === [] ? 0 : 1;
}

/* ------------------------------------------------------------------ git */

/**
 * The release tags, oldest first, by version.
 *
 * @return list<string>
 */
function releaseTags(): array
{
    static $tags = null;

    if ($tags === null) {
        $tags = array_values(array_filter(
            explode("\n", trim((string) shell_exec('git tag -l "v*"'))),
            fn (string $tag): bool => preg_match('/^v\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $tag) === 1,
        ));
        usort($tags, fn (string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));
    }

    return $tags;
}

/**
 * Whether a tag's tree holds a path.
 */
function tagHolds(string $tag, string $path): bool
{
    static $trees = [];

    if (! isset($trees[$tag])) {
        $trees[$tag] = array_flip(explode("\n", trim((string) shell_exec('git ls-tree -r --name-only '.escapeshellarg($tag)))));
    }

    return isset($trees[$tag][$path]);
}

/**
 * The names a file has had, its current one first, following renames.
 *
 * @return list<string>
 */
function namesOf(string $path): array
{
    $names = [$path];
    $log = (string) shell_exec('git log --follow --name-only --format= -- '.escapeshellarg($path).' 2>/dev/null');

    foreach (explode("\n", $log) as $name) {
        if ($name !== '' && ! in_array($name, $names, true)) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * The file at a tag, under whichever of its names the tag holds, or null.
 */
function fileAtTag(string $tag, string $path): ?string
{
    static $names = [];

    if (tagHolds($tag, $path)) {
        return blob($tag, $path);
    }

    $names[$path] ??= namesOf($path);

    foreach ($names[$path] as $name) {
        if ($name !== $path && tagHolds($tag, $name)) {
            return blob($tag, $name);
        }
    }

    return null;
}

/**
 * One file's contents at a tag, through one long-lived `git cat-file`.
 */
function blob(string $tag, string $path): ?string
{
    static $process = null;
    static $pipes = [];

    if ($process === null) {
        $process = proc_open(['git', 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    }

    fwrite($pipes[0], $tag.':'.$path."\n");
    $line = (string) fgets($pipes[1]);

    if (str_ends_with(trim($line), 'missing')) {
        return null;
    }

    $size = (int) (explode(' ', trim($line))[2] ?? 0);
    $contents = '';

    while (strlen($contents) < $size + 1) {
        $contents .= (string) fread($pipes[1], $size + 1 - strlen($contents));
    }

    return substr($contents, 0, $size);
}

/**
 * The first release that held a file, from this clone's history, or `next`.
 */
function sinceFromGit(string $path): string
{
    foreach (releaseTags() as $tag) {
        if (fileAtTag($tag, $path) !== null) {
            return substr($tag, 1);
        }
    }

    return 'next';
}

/**
 * The release a member first appeared in, or `next`.
 */
function memberSinceFromGit(string $path, string $member): string
{
    foreach (releaseTags() as $tag) {
        $source = fileAtTag($tag, $path);

        if ($source !== null && memberIn($source, $member)) {
            return substr($tag, 1);
        }
    }

    return 'next';
}

/**
 * Whether source declares a member, `method:name` or `const:NAME`.
 */
function memberIn(string $source, string $member): bool
{
    [$kind, $name] = explode(':', $member, 2);
    $pattern = $kind === 'method'
        ? '/\bfunction\s+&?'.preg_quote($name, '/').'\s*\(/'
        : '/\b(?:const|case)\s+(?:[\w\\\\|?]+\s+)?'.preg_quote($name, '/').'\s*[=;]/';

    return preg_match($pattern, $source) === 1;
}

/**
 * @return array{files: array<string, string>, members: array<string, array<string, string>>}
 */
function loadMap(?string $file): array
{
    if ($file === null) {
        return ['files' => [], 'members' => []];
    }

    $map = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    return ['files' => $map['files'] ?? [], 'members' => $map['members'] ?? []];
}

/**
 * A file's first release: the map's answer for any of its names, else this clone's.
 *
 * @param  array{files: array<string, string>, members: array<string, array<string, string>>}  $map
 */
function sinceOf(string $path, array $map): string
{
    $fromGit = sinceFromGit($path);

    foreach (mappedNames($path, $map) as $name) {
        if (isset($map['files'][$name])) {
            return earlier($map['files'][$name], $fromGit);
        }
    }

    return $fromGit;
}

/**
 * @param  array{files: array<string, string>, members: array<string, array<string, string>>}  $map
 *
 * @return list<string>
 */
function mappedNames(string $path, array $map): array
{
    if ($map['files'] === []) {
        return [];
    }

    return isset($map['files'][$path]) ? [$path] : namesOf($path);
}

function earlier(string $a, string $b): string
{
    if ($a === 'next') {
        return $b;
    }

    if ($b === 'next') {
        return $a;
    }

    return version_compare($a, $b) <= 0 ? $a : $b;
}

function printMap(): int
{
    $map = ['files' => [], 'members' => []];

    foreach (coveredFiles() as $path) {
        $since = sinceFromGit($path);

        if ($since === 'next') {
            continue;
        }

        $map['files'][$path] = $since;
        $class = classOf((string) file_get_contents($path));

        if ($class === null) {
            continue;
        }

        foreach (publicMembers((string) file_get_contents($path), $class) as $member => $unused) {
            $memberSince = memberSinceFromGit($path, $member);

            if ($memberSince !== 'next') {
                $map['members'][$path][$member] = $memberSince;
            }
        }
    }

    echo json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

    return 0;
}

/* --------------------------------------------------------------- source */

/**
 * The one class-like declaration of a file: its keyword token's index and
 * the index of its docblock, or null when the file declares none or several.
 *
 * @return array{keyword: int, doc: int|null}|null
 */
function classOf(string $source): ?array
{
    $tokens = PhpToken::tokenize($source);
    $found = [];
    $depth = 0;

    foreach ($tokens as $index => $token) {
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        }

        if ($depth !== 0 || ! $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            continue;
        }

        $previous = previousSignificant($tokens, $index);

        if ($previous !== null && $tokens[$previous]->is([T_DOUBLE_COLON])) {
            continue;
        }

        $found[] = $index;
    }

    if (count($found) !== 1) {
        return null;
    }

    return ['keyword' => $found[0], 'doc' => docBefore($tokens, $found[0])];
}

/**
 * @param  list<PhpToken>  $tokens
 */
function previousSignificant(array $tokens, int $index): ?int
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * The docblock that belongs to a declaration, stepping back over its
 * modifiers and attributes (and over `return new` for an anonymous class).
 *
 * @param  list<PhpToken>  $tokens
 */
function docBefore(array $tokens, int $index): ?int
{
    $i = $index - 1;

    while ($i >= 0) {
        $token = $tokens[$i];

        if ($token->is([T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY, T_NEW, T_RETURN, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC])) {
            $i--;

            continue;
        }

        if ($token->text === ']') {
            $depth = 0;

            for (; $i >= 0; $i--) {
                if ($tokens[$i]->text === ']') {
                    $depth++;
                } elseif ($tokens[$i]->text === '[') {
                    $depth--;
                } elseif ($tokens[$i]->is(T_ATTRIBUTE)) {
                    $depth--;
                }

                if ($depth === 0) {
                    break;
                }
            }

            $i--;

            continue;
        }

        return $token->is(T_DOC_COMMENT) ? $i : null;
    }

    return null;
}

/**
 * A docblock's lines without their delimiters and leading stars.
 *
 * @return list<string>
 */
function docLines(string $doc): array
{
    $doc = (string) preg_replace('#^/\*\*\s?|\s*\*/$#', '', $doc);
    $lines = [];

    foreach (explode("\n", $doc) as $line) {
        $lines[] = rtrim((string) preg_replace('/^\s*\* ?/', '', $line));
    }

    while ($lines !== [] && trim($lines[0]) === '') {
        array_shift($lines);
    }

    while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
        array_pop($lines);
    }

    return $lines;
}

/**
 * A docblock split into its prose paragraphs and the rest (its tags).
 *
 * @return array{paragraphs: list<list<string>>, tags: list<string>}
 */
function splitDoc(string $doc): array
{
    $paragraphs = [];
    $current = [];
    $tags = [];

    foreach (docLines($doc) as $line) {
        if ($tags !== [] || str_starts_with(ltrim($line), '@')) {
            $tags[] = $line;

            continue;
        }

        if (trim($line) === '') {
            if ($current !== []) {
                $paragraphs[] = $current;
                $current = [];
            }

            continue;
        }

        $current[] = $line;
    }

    if ($current !== []) {
        $paragraphs[] = $current;
    }

    return ['paragraphs' => $paragraphs, 'tags' => $tags];
}

/**
 * A docblock at an indent, from its lines; one-line when a lone tag is short.
 *
 * @param  list<string>  $lines
 */
function docblock(array $lines, string $indent = ''): string
{
    $out = $indent."/**\n";

    foreach ($lines as $line) {
        $out .= $indent.($line === '' ? ' *' : ' * '.$line)."\n";
    }

    return $out.$indent.' */';
}

/**
 * Text wrapped to lines of at most 76 characters.
 *
 * @return list<string>
 */
function wrap(string $text): array
{
    return explode("\n", wordwrap(trim((string) preg_replace('/\s+/', ' ', $text)), 76, "\n", false));
}

function firstSentence(string $text): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    return preg_match('/^(.+?[.!?])(?:\s+[A-Z`(]|$)/u', $text, $match) === 1 ? $match[1] : $text;
}

/* ---------------------------------------------------------------- apply */

/**
 * @param  list<string>  $only
 * @param  array<string, string>  $options
 */
function apply(array $only, array $options): int
{
    $map = loadMap($options['map'] ?? null);
    $describe = isset($options['describe']) ? json_decode((string) file_get_contents($options['describe']), true, flags: JSON_THROW_ON_ERROR) : [];
    $changed = 0;
    $undescribed = [];

    foreach (coveredFiles($only) as $path) {
        $source = (string) file_get_contents($path);

        if (headerOf($source) !== null) {
            continue;
        }

        $result = withHeader($path, $source, $map, $describe[$path] ?? null);

        if ($result === null) {
            $undescribed[] = $path;

            continue;
        }

        file_put_contents($path, $result);
        $changed++;
    }

    fwrite(STDOUT, "{$changed} files given a header.\n");

    foreach ($undescribed as $path) {
        fwrite(STDERR, "{$path}: no sentence to describe it. Write its header by hand.\n");
    }

    return $undescribed === [] ? 0 : 1;
}

/**
 * The file with its header added, the class docblock's prose moved into
 * it, or null when nothing says what the file is for.
 *
 * @param  array{files: array<string, string>, members: array<string, array<string, string>>}  $map
 */
function withHeader(string $path, string $source, array $map, ?string $sentence = null): ?string
{
    if (preg_match('#\A<\?php\n\ndeclare\(strict_types=1\);\n#', $source) !== 1) {
        return null;
    }

    $since = extensionOf($path) !== null ? 'next' : sinceOf($path, $map);
    $prose = null;
    $class = classOf($source);

    if ($class !== null && $class['doc'] !== null) {
        $tokens = PhpToken::tokenize($source);
        $docToken = $tokens[$class['doc']];
        $parts = splitDoc($docToken->text);

        if ($parts['paragraphs'] !== []) {
            $keep = array_filter(KEEP_CLASS_PROSE, fn (string $prefix): bool => str_starts_with($path, $prefix)) !== [];

            if ($keep) {
                $prose = wrap(firstSentence(implode(' ', $parts['paragraphs'][0])));
                $contractSince = classSinceTag($parts['tags']);
                $since = $contractSince ?? $since;
            } else {
                $prose = $parts['paragraphs'][0];
                $rest = [];

                foreach (array_slice($parts['paragraphs'], 1) as $paragraph) {
                    $rest = [...$rest, ...$paragraph, ''];
                }

                $rest = [...$rest, ...$parts['tags']];

                while ($rest !== [] && $rest[count($rest) - 1] === '') {
                    array_pop($rest);
                }

                $indent = indentBefore($source, $docToken->pos);
                $replacement = $rest === [] ? '' : docblock($rest, $indent);
                $start = $docToken->pos;
                $end = $docToken->pos + strlen($docToken->text);

                if ($replacement === '') {
                    // Drop the docblock and the line break after it.
                    $start -= strlen($indent);
                    $end += strspn($source, "\n", $end, 1);
                } else {
                    $start -= strlen($indent);
                }

                $source = substr($source, 0, $start).$replacement.substr($source, $end);
            }
        }
    }

    $prose ??= $sentence === null ? describedByName($path, $source) : wrap($sentence);

    if ($prose === null) {
        return null;
    }

    ['package' => $package, 'license' => $license] = packageOf($path);
    $header = docblock([...$prose, '', '@package '.$package, '@since   '.$since, '@license '.$license]);

    return "<?php\n\n".$header."\n\n".substr($source, strlen("<?php\n\n"));
}

/**
 * @param  list<string>  $tags
 */
function classSinceTag(array $tags): ?string
{
    foreach ($tags as $tag) {
        if (preg_match('/^@since\s+('.SINCE_PATTERN.')\b/', trim($tag), $match) === 1) {
            return $match[1];
        }
    }

    return null;
}

function indentBefore(string $source, int $position): string
{
    $lineStart = strrpos(substr($source, 0, $position), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $indent = substr($source, $lineStart, $position - $lineStart);

    return trim($indent) === '' ? $indent : '';
}

/**
 * A sentence for a file whose class says nothing, from where it lives:
 * a migration from its name, a factory from the model it makes.
 *
 * @return list<string>|null
 */
function describedByName(string $path, string $source): ?array
{
    if (preg_match('#(?:^|/)database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_(\w+)\.php$#', $path, $match) === 1) {
        $words = explode('_', $match[1]);
        $words[0] = thirdPerson($words[0]);

        return wrap('Migration: '.implode(' ', $words).'.');
    }

    if (preg_match('#@extends\s+Factory<(\w+)>#', $source, $match) === 1) {
        return wrap("Makes {$match[1]} models for tests and seeding.");
    }

    return null;
}

function thirdPerson(string $verb): string
{
    return match (true) {
        preg_match('/(s|sh|ch|x|z)$/', $verb) === 1 => $verb.'es',
        preg_match('/[^aeiou]y$/', $verb) === 1 => substr($verb, 0, -1).'ies',
        default => $verb.'s',
    };
}

/* -------------------------------------------------------------- members */

/**
 * The public methods and constants a class declares itself, keyed
 * `method:name` or `const:NAME`, each with its token index.
 *
 * @param  array{keyword: int, doc: int|null}  $class
 *
 * @return array<string, int>
 */
function publicMembers(string $source, array $class): array
{
    $tokens = PhpToken::tokenize($source);
    $members = [];
    $depth = 0;
    $entered = false;

    for ($i = $class['keyword']; $i < count($tokens); $i++) {
        $token = $tokens[$i];

        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
            $entered = true;

            continue;
        }

        if ($token->text === '}') {
            $depth--;

            if ($entered && $depth === 0) {
                break;
            }

            continue;
        }

        if ($depth !== 1) {
            continue;
        }

        if ($token->is(T_FUNCTION)) {
            $name = nextSignificant($tokens, $i);

            if ($name !== null && $tokens[$name]->text === '&') {
                $name = nextSignificant($tokens, $name);
            }

            if ($name === null || str_starts_with($tokens[$name]->text, '__') || ! isPublic($tokens, $i)) {
                continue;
            }

            $members['method:'.$tokens[$name]->text] = $i;
        }

        if ($token->is(T_CONST) && isPublic($tokens, $i)) {
            $name = null;

            for ($j = nextSignificant($tokens, $i); $j !== null && $tokens[$j]->text !== '=' && $tokens[$j]->text !== ';'; $j = nextSignificant($tokens, $j)) {
                $name = $j;
            }

            if ($name !== null) {
                $members['const:'.$tokens[$name]->text] = $i;
            }
        }
    }

    return $members;
}

/**
 * @param  list<PhpToken>  $tokens
 */
function nextSignificant(array $tokens, int $index): ?int
{
    for ($i = $index + 1; $i < count($tokens); $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * Whether the member declared at a token is public: `public`, or no
 * visibility at all.
 *
 * @param  list<PhpToken>  $tokens
 */
function isPublic(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if ($token->is([T_WHITESPACE, T_COMMENT, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
            continue;
        }

        if ($token->is([T_PRIVATE, T_PROTECTED])) {
            return false;
        }

        return true;
    }

    return true;
}

/**
 * @param  list<string>  $only
 * @param  array<string, string>  $options
 */
function members(array $only, array $options): int
{
    $map = loadMap($options['map'] ?? null);
    $tagged = 0;
    $files = array_filter(coveredFiles($only === [] ? ['app'] : $only), fn (string $path): bool => str_starts_with($path, 'app/'));

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $header = headerOf($source);
        $class = classOf($source);

        if ($header === null || $class === null || $header['since'] === 'next') {
            continue;
        }

        $additions = [];

        foreach (publicMembers($source, $class) as $member => $index) {
            $since = null;

            foreach (mappedNames($path, $map) as $name) {
                if (isset($map['members'][$name][$member])) {
                    $since = $map['members'][$name][$member];

                    break;
                }
            }

            $fromGit = memberSinceFromGit($path, $member);
            $since = $since === null ? $fromGit : earlier($since, $fromGit);

            if ($since === 'next' || version_compare($since, $header['since']) > 0) {
                $additions[$index] = $since;
            }
        }

        if ($additions === []) {
            continue;
        }

        file_put_contents($path, withMemberSince($source, $additions));
        $tagged += count($additions);
    }

    fwrite(STDOUT, "{$tagged} public members given @since.\n");

    return 0;
}

/**
 * The source with `@since` added to each member's docblock, last first so
 * earlier offsets hold.
 *
 * @param  array<int, string>  $additions  token index of `function` or `const` => version
 */
function withMemberSince(string $source, array $additions): string
{
    $tokens = PhpToken::tokenize($source);
    krsort($additions);

    foreach ($additions as $index => $since) {
        $doc = docBefore($tokens, $index);

        if ($doc !== null && preg_match('/@since\b/', $tokens[$doc]->text) === 1) {
            continue;
        }

        if ($doc !== null) {
            $token = $tokens[$doc];
            $indent = indentBefore($source, $token->pos);
            $lines = docLines($token->text);
            $lines = [...$lines, ...($lines === [] ? [] : ['']), '@since '.$since];
            $source = substr($source, 0, $token->pos).ltrim(docblock($lines, $indent)).substr($source, $token->pos + strlen($token->text));

            continue;
        }

        $start = declarationStart($tokens, $index);
        $position = $tokens[$start]->pos;
        $indent = indentBefore($source, $position);
        $source = substr($source, 0, $position).ltrim(docblock(['@since '.$since], $indent))."\n".$indent.substr($source, $position);
    }

    return $source;
}

/**
 * The first token of a member's declaration: its attributes and modifiers.
 *
 * @param  list<PhpToken>  $tokens
 */
function declarationStart(array $tokens, int $index): int
{
    $start = $index;

    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if ($token->is(T_WHITESPACE)) {
            continue;
        }

        if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
            $start = $i;

            continue;
        }

        if ($token->text === ']') {
            $depth = 0;

            for (; $i >= 0; $i--) {
                if ($tokens[$i]->text === ']') {
                    $depth++;
                } elseif ($tokens[$i]->text === '[' || $tokens[$i]->is(T_ATTRIBUTE)) {
                    $depth--;
                }

                if ($depth === 0) {
                    break;
                }
            }

            $start = $i;

            continue;
        }

        break;
    }

    return $start;
}
