<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use FilesystemIterator;
use PhpToken;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Teme\Contracts\Plugins\Plugin;
use UnexpectedValueException;

/**
 * Reads a plugin's code without ever running it (I-3353): which files
 * Composer would load for it, what its own class says of itself, and
 * whether it writes any name in Teme's own App namespace. Nothing here
 * includes, autoloads or instantiates a plugin's class. A plugin that
 * declares autoload.files is refused, but Composer has already included
 * those files on every request since the package was installed, and Teme
 * can neither prevent that nor see into the plugin's own dependencies.
 * The scan is there to catch a plugin reaching into Teme's own code by
 * mistake; it can't see an alias such as `use App as A`, a name built from
 * strings, or a facade, so it stops honest mistakes, not hidden intent.
 */
final readonly class CodeScan
{
    /** The most PHP files one plugin's scan reads; one more refuses it. */
    public const int MOST_FILES = 2000;

    /** The largest PHP file the scan reads, in bytes; a larger one refuses the plugin. */
    public const int MOST_BYTES = 1_048_576;

    /** Types PHP itself defines, which are never a class a plugin is given. */
    private const array BUILTIN = ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'self', 'static', 'parent', 'string', 'true', 'void'];

    /**
     * Every PHP file Composer would load for the plugin, in sorted path
     * order: those under its autoload's psr-4, psr-0 and classmap paths, or
     * for a folder with no composer.json every PHP file in it; never one
     * under its top-level vendor, tests or node_modules folder, never
     * autoload-dev's, and never one the scan refuses (see limits()).
     *
     * @param  array<string, mixed>|null  $composer
     *
     * @return list<string>
     */
    public function sourceFiles(string $path, ?array $composer): array
    {
        return $this->walk($path, $composer)['files'];
    }

    /**
     * Why the scan can't read the plugin whole: a file it would load that
     * lies outside its folder, more PHP files than MOST_FILES, or one larger
     * than MOST_BYTES. Empty when none.
     *
     * @param  array<string, mixed>|null  $composer
     *
     * @return list<string>
     */
    public function limits(string $path, ?array $composer): array
    {
        return $this->walk($path, $composer)['problems'];
    }

    /**
     * The files the scan reads, bounded, and why it refuses the rest.
     *
     * @param  array<string, mixed>|null  $composer
     *
     * @return array{files: list<string>, problems: list<string>}
     */
    private function walk(string $path, ?array $composer): array
    {
        $root = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/').'/';
        $base = rtrim(str_replace('\\', '/', $path), '/').'/';
        $files = [];
        $outside = null;
        $large = null;

        foreach ($composer === null ? [''] : $this->autoloadPaths($composer) as $folder) {
            $at = rtrim($base.$folder, '/');

            if ($this->skipped($base, $at)) {
                continue;
            }

            try {
                $found = is_dir($at) && ! is_link($at)
                    ? new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator($at, FilesystemIterator::SKIP_DOTS),
                        fn (SplFileInfo $file): bool => ! $this->skipped($base, $file->getPathname()),
                    ), RecursiveIteratorIterator::SELF_FIRST)
                    : (file_exists($at) || is_link($at) ? [new SplFileInfo($at)] : []);

                foreach ($found as $file) {
                    if (! $file instanceof SplFileInfo) {
                        continue;
                    }

                    $name = $file->getPathname();
                    $real = str_replace('\\', '/', realpath($name) ?: $name);

                    if (($file->isLink() || $real !== str_replace('\\', '/', $name)) && ! str_starts_with($real, $root)) {
                        $outside ??= substr(str_replace('\\', '/', $name), strlen($base));

                        continue;
                    }

                    if (! $file->isFile() || $file->getExtension() !== 'php' || isset($files[$name])) {
                        continue;
                    }

                    if ($file->getSize() > self::MOST_BYTES) {
                        $large ??= substr(str_replace('\\', '/', $name), strlen($base));

                        continue;
                    }

                    $files[$name] = true;

                    if (count($files) > self::MOST_FILES) {
                        break 2;
                    }
                }
            } catch (UnexpectedValueException $failure) {
                // A folder that can't be listed: named by its path inside the plugin, PHP's message kept for the log.
                throw new PluginFileUnreadable($folder !== '' ? $folder : basename($path), PluginFileUnreadable::OPENED, $failure);
            }
        }

        $problems = [];

        if ($outside !== null) {
            $problems[] = "It links to a file outside its own folder, so Teme can’t check it: {$outside}.";
        }

        if (count($files) > self::MOST_FILES) {
            $problems[] = 'It has more than '.self::MOST_FILES.' PHP files, so Teme can’t check them all.';
        }

        if ($large !== null) {
            $problems[] = "{$large} is larger than 1 MB, so Teme can’t check it.";
        }

        $files = array_keys($files);
        sort($files);

        return ['files' => array_slice($files, 0, self::MOST_FILES), 'problems' => $problems];
    }

    /**
     * Whether this file or folder lies under the plugin's top-level vendor,
     * tests or node_modules folder, which Composer never loads for it.
     */
    private function skipped(string $base, string $name): bool
    {
        $relative = substr(str_replace('\\', '/', $name), strlen($base));
        $first = strtolower(explode('/', $relative)[0]);

        return str_starts_with(str_replace('\\', '/', $name), $base) && in_array($first, ['vendor', 'tests', 'node_modules'], true);
    }

    /** A name whose first segment is App, in any case: App\…, \App\…, or App\ before a group's brace. */
    private const string APP = '/^\\\\?app\\\\/i';

    /**
     * Why the plugin's code may not be used: the Composer settings that
     * would run it while disabled, then the first name in Teme's App
     * namespace it writes, with how many more there are. Empty when none.
     *
     * @param  array<string, mixed>|null  $composer
     *
     * @return list<string>
     */
    public function problems(string $path, ?array $composer): array
    {
        $problems = [];
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];

        if (($autoload['files'] ?? []) !== []) {
            $problems[] = 'It asks Composer to run files on every request, so it would run even while disabled.';
        }

        if (array_key_exists('laravel', $extra)) {
            $problems[] = 'It asks Laravel to load it, so it would run even while disabled.';
        }

        array_push($problems, ...$this->limits($path, $composer));

        $root = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/').'/';
        $first = null;
        $count = 0;

        foreach ($this->sourceFiles($path, $composer) as $file) {
            $names = $this->namesIn(PluginFileUnreadable::read($path, $file));

            if ($names !== [] && $first === null) {
                $relative = substr(str_replace('\\', '/', realpath($file) ?: $file), strlen($root));
                $first = "It uses Teme’s own code, which plugins may not use: {$names[0][0]} in {$relative}, line {$names[0][1]}.";
            }

            $count += count($names);
        }

        if ($first !== null) {
            $problems[] = $count > 1 ? $first.' (and '.($count - 1).' more)' : $first;
        }

        return $problems;
    }

    /**
     * Each name in Teme's App namespace this PHP source writes, with its line:
     * a qualified name (in code or a use statement), a group use, a namespace
     * declaration, or a plain string holding such a name. PHP ignores case in
     * class names, so this does too. Comments, whitespace and words such as
     * $app or 'Apple' are never names. A relative App\Foo inside another
     * namespace is reported too: rare, and renaming it is cheap (I-3353).
     *
     * @return list<array{0: string, 1: int}>
     */
    public function namesIn(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $found = [];

        foreach ($tokens as $at => $token) {
            $before = $tokens[$at - 1] ?? null;
            $after = $tokens[$at + 1] ?? null;

            $name = match (true) {
                $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) => $token->text,
                $token->is(T_STRING) && $after?->is(T_NS_SEPARATOR) === true => $token->text.'\\',
                $token->is(T_STRING) && $before?->is(T_NAMESPACE) === true => $token->text.'\\',
                $token->is(T_CONSTANT_ENCAPSED_STRING) => str_replace('\\\\', '\\', substr($token->text, 1, -1)),
                default => null,
            };

            if ($name !== null && preg_match(self::APP, $name) === 1) {
                $found[] = [rtrim($name, '\\'), $token->line];
            }
        }

        return $found;
    }

    /**
     * What the plugin's own class says of itself, read from its source and
     * never loaded: whether it, or a parent or interface of the plugin's own,
     * implements Teme\Contracts\Plugins\Plugin, and what each constructor
     * parameter asks for. `unreadable` names what the tokens can't follow
     * without running code (a parent or interface outside the plugin's own
     * files and outside teme/contracts, a constructor a trait supplies, a
     * class declared inside an if or a function), which is refused, never guessed.
     * Null when no file of the plugin declares the class.
     *
     * @param  array<string, mixed>|null  $composer
     *
     * @return array{plugin: bool, parameters: list<array{name: string, type: string|null, named: bool}>, unreadable: array{0: string, 1: string}|null}|null
     */
    public function pluginClass(string $path, ?array $composer, string $class): ?array
    {
        $declared = $this->declarations($path, $this->sourceFiles($path, $composer));
        $key = strtolower($class);
        $own = $declared[$key] ?? null;

        if ($own === null || $own['kind'] !== 'class') {
            return null;
        }

        if ($own['conditional']) {
            return ['plugin' => false, 'parameters' => [], 'unreadable' => ['conditional', $class]];
        }

        $foreign = [];
        $plugin = $this->reaches($declared, $key, strtolower(Plugin::class), 0, $foreign);
        [$parameters, $unreadable] = $this->constructorOf($declared, $key, 0);

        if (! $plugin && $foreign !== []) {
            $unreadable ??= $foreign[0];
        }

        return ['plugin' => $plugin, 'parameters' => $parameters, 'unreadable' => $unreadable];
    }

    /**
     * The folders and files the plugin's composer.json autoloads, relative to it.
     *
     * @param  array<string, mixed>  $composer
     *
     * @return list<string>
     */
    private function autoloadPaths(array $composer): array
    {
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $paths = [];

        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (is_array($autoload[$standard] ?? null) ? $autoload[$standard] : [] as $folders) {
                foreach ((array) $folders as $folder) {
                    if (is_string($folder)) {
                        $paths[] = $folder;
                    }
                }
            }
        }

        foreach (is_array($autoload['classmap'] ?? null) ? $autoload['classmap'] : [] as $entry) {
            if (is_string($entry)) {
                $paths[] = $entry;
            }
        }

        return array_map(fn (string $folder): string => trim(str_replace('\\', '/', $folder), '/'), $paths);
    }

    /**
     * Every class and interface these files declare, by lower-case full name.
     *
     * @param  list<string>  $files
     *
     * @return array<string, array{kind: string, parents: list<string>, interfaces: list<string>, traits: list<string>, conditional: bool, parameters: list<array{name: string, type: string|null, named: bool}>|null}>
     */
    private function declarations(string $path, array $files): array
    {
        $declared = [];

        foreach ($files as $file) {
            $declared += $this->declarationsIn(PluginFileUnreadable::read($path, $file));
        }

        return $declared;
    }

    /**
     * The classes and interfaces one PHP source declares: for a class, its
     * parent, its interfaces and its constructor's parameters; for an
     * interface, the interfaces it extends. Names are resolved against the
     * file's namespace and use statements.
     *
     * @return array<string, array{kind: string, parents: list<string>, interfaces: list<string>, traits: list<string>, conditional: bool, parameters: list<array{name: string, type: string|null, named: bool}>|null}>
     */
    private function declarationsIn(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $namespace = '';
        $uses = [];
        $declared = [];
        $count = count($tokens);
        $depth = 0;
        $namespaceDepth = 0;

        for ($at = 0; $at < $count; $at++) {
            $token = $tokens[$at];

            if ($token->is(T_NAMESPACE) && ($tokens[$at + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED]) === true) {
                $namespace = $tokens[$at + 1]->text;
                $uses = [];
                $namespaceDepth = ($tokens[$at + 2] ?? null)?->text === '{' ? 1 : 0;

                continue;
            }

            if ($token->is(T_NAMESPACE) && ($tokens[$at + 1] ?? null)?->text === '{') {
                $namespace = '';
                $uses = [];
                $namespaceDepth = 1;

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($token->is(T_USE)) {
                $at = $this->readUse($tokens, $at + 1, $uses);

                continue;
            }

            $kind = match (true) {
                $token->is(T_CLASS) && ($tokens[$at - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW]) !== true => 'class',
                $token->is(T_INTERFACE) => 'interface',
                $token->is([T_TRAIT, T_ENUM]) => 'other',
                default => null,
            };

            if ($kind === null || ($tokens[$at + 1] ?? null)?->is(T_STRING) !== true) {
                continue;
            }

            $name = ltrim($namespace.'\\'.$tokens[$at + 1]->text, '\\');
            $parents = [];
            $interfaces = [];
            $list = null;
            $at += 2;

            for (; $at < $count && $tokens[$at]->text !== '{'; $at++) {
                if ($tokens[$at]->is(T_EXTENDS)) {
                    $list = $kind === 'interface' ? 'interfaces' : 'parents';
                } elseif ($tokens[$at]->is(T_IMPLEMENTS)) {
                    $list = 'interfaces';
                } elseif ($list !== null && $tokens[$at]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $resolved = $this->resolve($tokens[$at]->text, $namespace, $uses);
                    $list === 'parents' ? $parents[] = $resolved : $interfaces[] = $resolved;
                }
            }

            [$at, $parameters, $traits] = $this->readBody($tokens, $at, $namespace, $uses);
            $declared[strtolower($name)] ??= [
                'kind' => $kind, 'parents' => $parents, 'interfaces' => $interfaces, 'traits' => $traits,
                'conditional' => $depth > $namespaceDepth, 'parameters' => $parameters,
            ];
        }

        return $declared;
    }

    /**
     * Reads one use statement from $at, adding its imports, and returns
     * where it ends. A function or constant import is skipped.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $uses
     */
    private function readUse(array $tokens, int $at, array &$uses): int
    {
        $count = count($tokens);

        // A closure's use (...), not an import.
        if (($tokens[$at] ?? null)?->text === '(') {
            return $at - 1;
        }

        $skip = ($tokens[$at] ?? null)?->is([T_FUNCTION, T_CONST]) === true;
        $prefix = '';
        $name = null;
        $alias = null;

        for (; $at < $count && $tokens[$at]->text !== ';'; $at++) {
            $token = $tokens[$at];

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ($tokens[$at + 1] ?? null)?->is(T_NS_SEPARATOR) === true) {
                $prefix = ltrim($token->text, '\\').'\\';
                $at++;
            } elseif ($token->is(T_AS)) {
                $alias = $tokens[$at + 1]->text ?? null;
                $at++;
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = $prefix.ltrim($token->text, '\\');
            } elseif (in_array($token->text, [',', '}'], true) && $name !== null) {
                $skip || $uses[strtolower($alias ?? $this->shortName($name))] = $name;
                $name = $alias = null;
            }
        }

        if ($name !== null && ! $skip) {
            $uses[strtolower($alias ?? $this->shortName($name))] = $name;
        }

        return $at;
    }

    /**
     * Reads a class or interface body from its opening brace at $at, and
     * returns where it ends, its constructor's parameters (null when it
     * declares no constructor) and the traits it uses.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $uses
     *
     * @return array{0: int, 1: list<array{name: string, type: string|null, named: bool}>|null, 2: list<string>}
     */
    private function readBody(array $tokens, int $at, string $namespace, array $uses): array
    {
        $count = count($tokens);
        $depth = 0;
        $parameters = null;
        $traits = [];

        for (; $at < $count; $at++) {
            $text = $tokens[$at]->text;

            if ($text === '{' || $tokens[$at]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $tokens[$at]->is(T_FUNCTION) && strtolower($tokens[$at + 1]->text ?? '') === '__construct') {
                [$at, $parameters] = $this->readParameters($tokens, $at + 2, $namespace, $uses);
            } elseif ($depth === 1 && $tokens[$at]->is(T_USE)) {
                for ($at++; $at < $count && ! in_array($tokens[$at]->text, [';', '{'], true); $at++) {
                    if ($tokens[$at]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $traits[] = $this->resolve($tokens[$at]->text, $namespace, $uses);
                    }
                }

                $at--;
            }
        }

        return [$at, $parameters, $traits];
    }

    /**
     * Reads a parameter list from its opening bracket at $at: each
     * parameter's name and type, the type resolved when it is one class
     * name. Returns where the list ends.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $uses
     *
     * @return array{0: int, 1: list<array{name: string, type: string|null, named: bool}>}
     */
    private function readParameters(array $tokens, int $at, string $namespace, array $uses): array
    {
        $count = count($tokens);
        $depth = 0;
        $parameters = [];
        $type = [];
        $inDefault = false;

        for (; $at < $count; $at++) {
            $token = $tokens[$at];

            if (in_array($token->text, ['(', '['], true) || $token->is(T_ATTRIBUTE)) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            }

            if (in_array($token->text, [')', ']'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token->text === ',') {
                $type = [];
                $inDefault = false;
            } elseif ($token->text === '=') {
                $inDefault = true;
            } elseif ($token->is(T_VARIABLE) && ! $inDefault) {
                $parameters[] = $this->parameter(substr($token->text, 1), $type, $namespace, $uses);
                $type = [];
            } elseif (! $inDefault && ! $token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_ELLIPSIS, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG]) && ! in_array($token->text, ['?'], true)) {
                $type[] = $token;
            }
        }

        return [$at, $parameters];
    }

    /**
     * One parameter: its type resolved when it is a single class or
     * interface name, else as written; null when it has none.
     *
     * @param  list<PhpToken>  $type
     * @param  array<string, string>  $uses
     *
     * @return array{name: string, type: string|null, named: bool}
     */
    private function parameter(string $name, array $type, string $namespace, array $uses): array
    {
        if ($type === []) {
            return ['name' => $name, 'type' => null, 'named' => false];
        }

        if (count($type) === 1 && $type[0]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ! in_array(strtolower($type[0]->text), self::BUILTIN, true)) {
            return ['name' => $name, 'type' => $this->resolve($type[0]->text, $namespace, $uses), 'named' => true];
        }

        return ['name' => $name, 'type' => implode('', array_map(fn (PhpToken $token): string => $token->text, $type)), 'named' => false];
    }

    /**
     * A name as written in a file, resolved to its full name without a leading backslash.
     *
     * @param  array<string, string>  $uses
     */
    private function resolve(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $first = strtolower(explode('\\', $name)[0]);

        if (isset($uses[$first])) {
            return $uses[$first].substr($name, strlen($first));
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return end($parts);
    }

    /**
     * Whether a declared class or interface reaches $target through its
     * parents and interfaces, following only what the plugin itself
     * declares; each parent or interface it can't follow is added to
     * $foreign, but never one of teme/contracts, which can't reach Plugin
     * unless it is Plugin.
     *
     * @param  array<string, array{kind: string, parents: list<string>, interfaces: list<string>, traits: list<string>, conditional: bool, parameters: list<array{name: string, type: string|null, named: bool}>|null}>  $declared
     * @param  list<array{0: string, 1: string}>  $foreign
     */
    private function reaches(array $declared, string $name, string $target, int $depth, array &$foreign): bool
    {
        $own = $declared[$name] ?? null;

        if ($own === null || $depth > 10) {
            return false;
        }

        $found = false;

        foreach (['parent' => $own['parents'], 'interface' => $own['interfaces']] as $kind => $names) {
            foreach ($names as $next) {
                $lower = strtolower($next);

                if ($lower === $target) {
                    $found = true;
                } elseif (isset($declared[$lower])) {
                    $found = $this->reaches($declared, $lower, $target, $depth + 1, $foreign) || $found;
                } elseif (! str_starts_with($lower, 'teme\\contracts\\')) {
                    $foreign[] = [$kind, $next];
                }
            }
        }

        return $found;
    }

    /**
     * The constructor's parameters a class has, its own or a parent's of the
     * plugin's own, and what can't be followed: a constructor a trait may
     * supply, or a parent outside the plugin's own files.
     *
     * @param  array<string, array{kind: string, parents: list<string>, interfaces: list<string>, traits: list<string>, conditional: bool, parameters: list<array{name: string, type: string|null, named: bool}>|null}>  $declared
     *
     * @return array{0: list<array{name: string, type: string|null, named: bool}>, 1: array{0: string, 1: string}|null}
     */
    private function constructorOf(array $declared, string $name, int $depth): array
    {
        $own = $declared[$name] ?? null;

        if ($own === null || $depth > 10) {
            return [[], null];
        }

        if ($own['parameters'] !== null) {
            return [$own['parameters'], null];
        }

        if ($own['traits'] !== []) {
            return [[], ['trait', $own['traits'][0]]];
        }

        $parent = $own['parents'][0] ?? null;

        if ($parent === null) {
            return [[], null];
        }

        return isset($declared[strtolower($parent)]) ? $this->constructorOf($declared, strtolower($parent), $depth + 1) : [[], ['parent', $parent]];
    }
}
