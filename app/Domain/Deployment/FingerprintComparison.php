<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

/**
 * Two site fingerprints read and compared line by line (I-385): which lines
 * differ, which of the differences are the server's own work, and which
 * addresses the first one asked.
 */
final class FingerprintComparison
{
    private const string HEADING = '# Teme site fingerprint';

    /**
     * Whether the text is a fingerprint: its first line begins with the heading.
     */
    public static function isFingerprint(string $text): bool
    {
        return str_starts_with(self::clean($text)[0] ?? '', self::HEADING);
    }

    /**
     * The first line's words after the heading, which name the server and the time.
     */
    public static function origin(string $text): string
    {
        return substr(self::clean($text)[0] ?? '', strlen(self::HEADING.': '));
    }

    /**
     * Every compared or activity line keyed by its first two words (the
     * migration line by its first), its value the rest of the line; a later
     * duplicate keeps the first value.
     *
     * @return array<string, string>
     */
    public static function lines(string $text): array
    {
        $lines = [];

        foreach (self::clean($text) as $line) {
            if (str_starts_with($line, 'migration ')) {
                $lines['migration'] ??= substr($line, strlen('migration '));

                continue;
            }

            foreach (['table ', 'activity ', 'address '] as $kind) {
                if (! str_starts_with($line, $kind)) {
                    continue;
                }

                $parts = explode(' ', $line, 3);
                $lines[$parts[0].' '.($parts[1] ?? '')] ??= $parts[2] ?? '';
            }
        }

        return $lines;
    }

    /**
     * The addresses the text's address lines name, in order, each once.
     *
     * @return list<string>
     */
    public static function addresses(string $text): array
    {
        $addresses = [];

        foreach (array_keys(self::lines($text)) as $key) {
            if (str_starts_with($key, 'address ')) {
                $addresses[] = substr($key, strlen('address '));
            }
        }

        return $addresses;
    }

    /**
     * The sentences for each line the two texts disagree on, the server's
     * own work apart from the rest, and how many tables and addresses were compared.
     *
     * @return array{differs: list<string>, activity: list<string>, tables: int, addresses: int}
     */
    public static function between(string $here, string $there): array
    {
        $mine = self::lines($here);
        $theirs = self::lines($there);
        $differs = [];
        $activity = [];
        $tables = 0;
        $addresses = 0;

        foreach (array_unique([...array_keys($mine), ...array_keys($theirs)]) as $key) {
            $kind = explode(' ', $key)[0];

            if ($kind === 'table') {
                $tables++;
            } elseif ($kind === 'address') {
                $addresses++;
            }

            $left = $mine[$key] ?? 'none';
            $right = $theirs[$key] ?? 'none';

            if ($left === $right) {
                continue;
            }

            if ($kind === 'activity') {
                $activity[] = 'Activity, not compared: '.substr($key, strlen('activity ')).": {$left} here, {$right} there.";
            } else {
                $differs[] = "Differs: {$key}: {$left} here, {$right} there.";
            }
        }

        return ['differs' => $differs, 'activity' => $activity, 'tables' => $tables, 'addresses' => $addresses];
    }

    /**
     * The text's lines without a byte-order mark or carriage returns.
     *
     * @return list<string>
     */
    private static function clean(string $text): array
    {
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;

        return array_map(fn (string $line): string => rtrim($line, "\r"), explode("\n", $text));
    }
}
