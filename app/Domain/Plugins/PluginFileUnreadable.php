<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use RuntimeException;
use Throwable;

/**
 * A plugin's file or folder Teme couldn't read (I-3353): where it is inside
 * the plugin, and why in one word, so the person who reads it sees no
 * server path; PHP's own message stays in the previous exception, for the log.
 */
final class PluginFileUnreadable extends RuntimeException
{
    /** It couldn't be opened or listed. */
    public const string OPENED = 'opened';

    /** What it holds isn't what it should be. */
    public const string VALID = 'valid';

    /** It is larger than Teme reads. */
    public const string LARGE = 'large';

    public function __construct(
        public readonly string $place,
        public readonly string $why,
        ?Throwable $previous = null,
    ) {
        parent::__construct("{$place}: {$why}", 0, $previous);
    }

    /**
     * The contents of $file, a file inside the plugin folder $root, or this
     * exception naming it by its path inside the folder.
     */
    public static function read(string $root, string $file): string
    {
        if ((int) @filesize($file) > CodeScan::MOST_BYTES) {
            throw new self(self::inside($root, $file), self::LARGE);
        }

        try {
            $contents = file_get_contents($file);
        } catch (Throwable $failure) {
            throw new self(self::inside($root, $file), self::OPENED, $failure);
        }

        if ($contents === false) {
            throw new self(self::inside($root, $file), self::OPENED);
        }

        return $contents;
    }

    /**
     * $name's path inside the plugin folder $root, or its own name when it lies elsewhere.
     */
    public static function inside(string $root, string $name): string
    {
        $base = rtrim(str_replace('\\', '/', $root), '/').'/';
        $name = str_replace('\\', '/', $name);

        return str_starts_with($name, $base) ? substr($name, strlen($base)) : basename($name);
    }
}
