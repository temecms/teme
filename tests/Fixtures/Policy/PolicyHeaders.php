<?php

declare(strict_types=1);

namespace Tests\Fixtures\Policy;

use Illuminate\Testing\TestResponse;

/**
 * Reads a public page's content security policy in the policy's tests:
 * whichever header the mode sends, its hashes, and one directive's sources.
 */
final class PolicyHeaders
{
    /** The policy the response carries, enforced or report-only; '' for none. */
    public static function of(TestResponse $response): string
    {
        return (string) ($response->headers->get('Content-Security-Policy')
            ?? $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * Every 'sha256-…' source in the policy, in order.
     *
     * @return list<string>
     */
    public static function hashes(string $policy): array
    {
        preg_match_all("/'sha256-[A-Za-z0-9+\/=]+'/", $policy, $found);

        return array_values($found[0]);
    }

    /**
     * One directive's sources, in order; [] when the policy lacks it.
     *
     * @return list<string>
     */
    public static function sources(string $policy, string $directive): array
    {
        foreach (explode(';', $policy) as $part) {
            $words = preg_split('/\s+/', trim($part)) ?: [];

            if (($words[0] ?? '') === $directive) {
                return array_values(array_slice($words, 1));
            }
        }

        return [];
    }
}
