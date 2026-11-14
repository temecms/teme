<?php

declare(strict_types=1);

namespace Tests\Fixtures\Policy;

/**
 * A browser's policy report as each format's JSON, for the report reader's
 * and the endpoint's tests: with the site's own page by default.
 */
final class Reports
{
    /**
     * A level 2 report, `application/csp-report`: {"csp-report": {…}}.
     *
     * @param  array<string, mixed>  $body  members replacing the defaults
     */
    public static function level2(array $body = []): string
    {
        return (string) json_encode(['csp-report' => array_merge([
            'document-uri' => 'https://ledger.example/about/?x=1',
            'effective-directive' => 'img-src',
            'blocked-uri' => 'https://px.example.net/p.gif?u=42',
            'disposition' => 'report',
        ], $body)]);
    }

    /**
     * Level 3 reports, `application/reports+json`: a list of {"type", "body"}.
     *
     * @param  list<array<string, mixed>>  $bodies  each a report's body, replacing the defaults
     */
    public static function level3(array $bodies = [[]]): string
    {
        return (string) json_encode(array_map(fn (array $body): array => [
            'type' => 'csp-violation',
            'url' => 'https://ledger.example/about/',
            'body' => array_merge([
                'documentURL' => 'https://ledger.example/about/?x=1',
                'effectiveDirective' => 'img-src',
                'blockedURL' => 'https://px.example.net/p.gif?u=42',
                'disposition' => 'report',
            ], $body),
        ], $bodies));
    }
}
