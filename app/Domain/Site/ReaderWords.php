<?php

declare(strict_types=1);

namespace App\Domain\Site;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Where the words a reader sees come from (I-3360): core's public views and
 * the classes that word a public page, whose translations live in `lang/`,
 * and the default theme's views, whose translations live in its own `lang/`.
 * Read by the test that holds every shipped word file to them.
 */
final class ReaderWords
{
    /**
     * Each source: the folders and files its keys are read from, relative
     * to the project, and the folder its translations live in.
     *
     * @var array<string, array{paths: list<string>, words: string}>
     */
    public const array SOURCES = [
        'core' => [
            'paths' => [
                'resources/views/teme',
                'app/View/Components/Teme',
                'app/Domain/Site',
                'app/Http/Site',
                'app/Domain/Menus/NavigationTree.php',
                'app/Domain/Comments/Discussion.php',
                'app/Domain/Comments/Operations/SubmitComment.php',
            ],
            'words' => 'lang',
        ],
        'theme' => [
            'paths' => ['themes/default/views'],
            'words' => 'themes/default/lang',
        ],
    ];

    /**
     * The languages Teme ships reader words in, as their word files are named.
     *
     * @var list<string>
     */
    public const array LOCALES = ['es', 'fr', 'pt_BR', 'de', 'it', 'hi', 'ja'];

    /**
     * Every key one source asks the translator for, through `__()` or
     * `trans_choice()` with a literal, sorted.
     *
     * @return list<string>
     */
    public static function keys(string $source): array
    {
        $keys = [];

        foreach (self::SOURCES[$source]['paths'] as $path) {
            $full = base_path($path);
            $files = is_dir($full) ? self::phpFiles($full) : [$full];

            foreach ($files as $file) {
                $keys = [...$keys, ...self::keysIn((string) file_get_contents($file))];
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * The literal keys in one file's code.
     *
     * @return list<string>
     */
    public static function keysIn(string $code): array
    {
        preg_match_all('/\b(?:__|trans_choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")/u', $code, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match): string => ($match[2] ?? '') !== '' ? stripcslashes($match[2]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]),
            $matches,
        );
    }

    /**
     * The PHP files under one folder of the code, sorted.
     *
     * @return list<string>
     */
    private static function phpFiles(string $folder): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
