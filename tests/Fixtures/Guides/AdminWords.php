<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The words the admin says, read from its source: every quoted string and
 * every piece of text between tags in the pages and components under
 * resources/js and in the PHP under app/. A guide that sets a name in bold
 * is held to saying it as the admin does, in one of the files the page
 * names as its sources. A word the admin builds from parts is matched with
 * its parts left open.
 */
final class AdminWords
{
    /** What stands for a part the admin fills in when it draws the words. */
    private const string OPEN = "\x01";

    /** Files and folders every page may take words from: the shell and the shared controls. */
    public const array SHARED = [
        'resources/js/components/ui',
        'resources/js/components/list',
        'resources/js/layouts',
        'resources/js/components/app-sidebar.tsx',
        'resources/js/components/nav-main.tsx',
        'resources/js/components/account-menu.tsx',
        'resources/js/components/user-menu-content.tsx',
        'app/Providers',
        'app/Domain/Navigation',
    ];

    /** Folders of generated code, which holds no words. */
    private const array GENERATED = ['resources/js/routes', 'resources/js/actions', 'resources/js/wayfinder'];

    /** @var array<string, list<string>> */
    private static array $units = [];

    /**
     * Whether the admin says this name, as written, in the files given or in
     * the shared ones.
     *
     * @param  list<string>  $sources
     */
    public static function says(string $name, array $sources): bool
    {
        return self::found(self::normalise($name), [...$sources, ...self::SHARED]);
    }

    /**
     * Every file in the admin's source that says the name, for the message a
     * failing test prints so the page's sources can be mended.
     *
     * @return list<string>
     */
    public static function whereSaid(string $name): array
    {
        $wanted = self::normalise($name);
        $where = [];

        foreach (self::files(['resources/js', 'app']) as $file) {
            if (self::found($wanted, [$file])) {
                $where[] = $file;
            }
        }

        return $where;
    }

    /**
     * @param  list<string>  $places  files or folders, relative to the repository
     */
    private static function found(string $wanted, array $places): bool
    {
        $patterns = [];

        foreach (self::files($places) as $file) {
            foreach (self::unitsOf($file) as $unit) {
                if ($unit === $wanted) {
                    return true;
                }

                if (str_contains($unit, self::OPEN)) {
                    $patterns[] = $unit;
                }
            }
        }

        foreach ($patterns as $pattern) {
            $parts = array_map(fn (string $part): string => preg_quote($part, '/'), explode(self::OPEN, $pattern));

            if (implode('', $parts) !== '' && preg_match('/^'.implode('.+?', $parts).'$/su', $wanted) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source files in some files and folders, without generated code.
     *
     * @param  list<string>  $places
     *
     * @return list<string>
     */
    public static function files(array $places): array
    {
        $root = GuideFile::root();
        $files = [];

        foreach ($places as $place) {
            $full = "{$root}/{$place}";

            if (is_file($full)) {
                $files[] = $place;

                continue;
            }

            if (! is_dir($full)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                if (in_array($file->getExtension(), ['tsx', 'ts', 'php'], true) && ! self::isGenerated($relative)) {
                    $files[] = $relative;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private static function isGenerated(string $path): bool
    {
        foreach (self::GENERATED as $folder) {
            if (str_starts_with($path, $folder.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function unitsOf(string $file): array
    {
        return self::$units[$file] ??= self::extract((string) file_get_contents(GuideFile::root().'/'.$file));
    }

    /**
     * Quoted strings and text between tags, tidied, with the parts the admin
     * fills in left open.
     *
     * @return list<string>
     */
    public static function extract(string $source): array
    {
        $found = [];

        foreach ([
            "/'((?:[^'\\\\\n]|\\\\.)*)'/",
            '/"((?:[^"\\\\\n]|\\\\.)*)"/',
            '/`((?:[^`\\\\]|\\\\.)*)`/s',
            '/>([^<>]+)</',
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            array_push($found, ...$matches[1]);
        }

        $units = [];

        foreach ($found as $text) {
            $text = stripslashes($text);
            $text = (string) preg_replace(['/\$\{[^}]*\}/', '/\{[^{}]*\}/', '/%\d*\$?[sd]/', '/:[a-z_]+\b/'], self::OPEN, $text);
            $text = self::normalise(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));

            if ($text !== '' && mb_strlen($text) <= 400 && preg_match('/\p{L}/u', $text) === 1) {
                $units[$text] = true;
            }
        }

        return array_keys($units);
    }

    /** Curly quotes made straight, white space made single, the ends trimmed. */
    public static function normalise(string $text): string
    {
        $text = str_replace([mb_chr(0x2018), mb_chr(0x2019), mb_chr(0x201C), mb_chr(0x201D)], ["'", "'", '"', '"'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
