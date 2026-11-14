<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

/**
 * A host a plugin may reach (docs/architecture/plugins.md, Trust): a host
 * name, or one leading wildcard label, in lower case. One rule for consent
 * and for the outbound door, so the two can never disagree (I-3353).
 */
final class HostPattern
{
    /** api.example.com or *.example.com: letters, digits and hyphens, a dot between labels, a top label of letters; never an address, never localhost. */
    public const string PATTERN = '/^(?:\*\.)?(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D';

    /**
     * Whether $granted covers $asked: the same host; or a wildcard and a host
     * or wildcard below it. *.example.com covers api.example.com,
     * a.b.example.com and *.api.example.com, but never example.com itself,
     * and a host never covers a wildcard.
     */
    public static function covers(string $granted, string $asked): bool
    {
        if ($granted === $asked) {
            return true;
        }

        if (! str_starts_with($granted, '*.')) {
            return false;
        }

        $suffix = substr($granted, 1);
        $below = str_starts_with($asked, '*.') ? substr($asked, 1) : $asked;

        return str_ends_with($below, $suffix) && strlen($below) > strlen($suffix);
    }

    /**
     * Whether any of the granted patterns covers $asked.
     *
     * @param  list<string>  $granted
     */
    public static function anyCovers(array $granted, string $asked): bool
    {
        foreach ($granted as $pattern) {
            if (self::covers($pattern, $asked)) {
                return true;
            }
        }

        return false;
    }
}
