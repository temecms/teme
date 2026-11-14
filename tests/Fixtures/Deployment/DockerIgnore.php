<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

/**
 * .dockerignore read as Docker reads it (its patternmatcher): each line a
 * pattern, a leading ! letting back in what it matches, a pattern matching
 * a path or any folder above it, and the last pattern that matches
 * deciding. * stays within a folder, ** crosses folders.
 */
final class DockerIgnore
{
    /**
     * @return list<array{pattern: string, include: bool, regex: string}>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (preg_split('/\R/', ImageFiles::text('.dockerignore')) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $include = str_starts_with($line, '!');
            $pattern = trim(trim($include ? substr($line, 1) : $line), '/');
            $rules[] = ['pattern' => $pattern, 'include' => $include, 'regex' => self::regex($pattern)];
        }

        return $rules;
    }

    /** Whether Docker leaves the path, relative to the context, out of a build. */
    public static function excludes(string $path): bool
    {
        $candidates = [];
        $parts = explode('/', trim($path, '/'));

        for ($length = count($parts); $length > 0; $length--) {
            $candidates[] = implode('/', array_slice($parts, 0, $length));
        }

        $excluded = false;

        foreach (self::rules() as $rule) {
            foreach ($candidates as $candidate) {
                if (preg_match($rule['regex'], $candidate) === 1) {
                    $excluded = ! $rule['include'];

                    break;
                }
            }
        }

        return $excluded;
    }

    private static function regex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($at = 0; $at < $length; $at++) {
            $character = $pattern[$at];

            if ($character === '*' && ($pattern[$at + 1] ?? '') === '*') {
                $at++;

                if (($pattern[$at + 1] ?? '') === '/') {
                    $at++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }

                continue;
            }

            $regex .= match ($character) {
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($character, '#'),
            };
        }

        return '#^'.$regex.'$#';
    }
}
