<?php

/**
 * Reads a browser's policy reports from a request's body (I-3375): level 2's
 * one report and level 3's list, kept to the few things Teme aggregates and
 * never a path or query that might carry a token. A body that isn't one of
 * those gives nothing; so does a report from an extension or about another
 * site's page. Nothing it returns holds a control, format, private-use or
 * unassigned character or invalid UTF-8: a report that did is dropped whole,
 * never repaired, because the table's jsonb refuses a NUL and a console
 * would act on an escape sequence.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\StaticPages;

final class PolicyReportReader
{
    /** Reports one body may hold. */
    private const int MOST_REPORTS = 20;

    /** Blocked values that name no address and are kept as they are. */
    private const array KEYWORDS = ['eval', 'wasm-eval', 'trusted-types-policy', 'trusted-types-sink'];

    /** Schemes of browser extensions, whose reports say nothing about the site. */
    private const array EXTENSIONS = ['chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension'];

    /**
     * The reports in a body of this content type, at most twenty; none when
     * the type or the JSON isn't one Teme reads.
     *
     * @return list<PolicyReport>
     */
    public static function read(?string $type, string $body): array
    {
        $media = strtolower(trim(explode(';', (string) $type)[0]));

        if (! in_array($media, ['application/csp-report', 'application/reports+json', 'application/json'], true)) {
            return [];
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            return [];
        }

        $items = [];

        if (isset($json['csp-report']) && is_array($json['csp-report']) && in_array($media, ['application/csp-report', 'application/json'], true)) {
            $items[] = $json['csp-report'];
        } elseif (array_is_list($json) && in_array($media, ['application/reports+json', 'application/json'], true)) {
            foreach ($json as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                    $items[] = $item['body'];
                }
            }
        }

        $reports = [];

        foreach ($items as $item) {
            $report = self::report($item);

            if ($report !== null) {
                $reports[] = $report;
            }

            if (count($reports) === self::MOST_REPORTS) {
                break;
            }
        }

        return $reports;
    }

    /**
     * One report's body as Teme keeps it, or null when it is to be dropped.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function report(array $body): ?PolicyReport
    {
        $directive = self::text($body, 'effective-directive', 'effectiveDirective');

        if ($directive === '') {
            $directive = (string) strtok(self::text($body, 'violated-directive', 'violatedDirective'), ' ');
        }

        if (! in_array($directive, PolicyReport::DIRECTIVES, true)) {
            return null;
        }

        $uri = self::text($body, 'blocked-uri', 'blockedURL');
        $document = self::text($body, 'document-uri', 'documentURL');
        $source = self::text($body, 'source-file', 'sourceFile');

        // What a report said, before it is reduced to an origin or a path, and as decoded: any dirty part drops it.
        if (! self::plain([$uri, $document, $source, rawurldecode((string) parse_url($document, PHP_URL_PATH))])) {
            return null;
        }

        $blocked = self::blocked($uri);
        $path = self::path($document);

        if ($blocked === null || $path === null || ($source !== '' && self::isExtension($source))) {
            return null;
        }

        $disposition = self::text($body, 'disposition', 'disposition') === 'enforce' ? 'enforce' : 'report';
        $sample = in_array($blocked, ['inline', 'eval'], true) ? self::sample($body, $source) : null;

        $texts = [$directive, $blocked, $path];

        foreach ($sample ?? [] as $kept) {
            $texts[] = (string) $kept;
        }

        if (! self::plain($texts)) {
            return null;
        }

        return new PolicyReport($directive, $blocked, $path, $disposition, $sample, time());
    }

    /**
     * A member of a report under its level 2 name, else its level 3 name,
     * when it is a string; '' otherwise.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function text(array $body, string $level2, string $level3): string
    {
        foreach ([$level2, $level3] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return '';
    }

    /**
     * What was blocked: its origin, never its path or query; a keyword or
     * a scheme as it is; 'inline' for none; null for an extension's address.
     */
    private static function blocked(string $uri): ?string
    {
        if ($uri === '' || $uri === 'inline') {
            return 'inline';
        }

        if (in_array($uri, self::KEYWORDS, true)) {
            return $uri;
        }

        if (self::isExtension($uri)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));

        if (in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            $origin = self::origin($uri);

            return $origin === null ? null : mb_substr($origin, 0, 255);
        }

        if ($scheme !== '') {
            return mb_substr($scheme.':', 0, 255);
        }

        // A bare word such as "data" or "blob": a scheme the browser named without its colon.
        return preg_match('/^[a-z][a-z0-9+.-]*$/i', $uri) === 1 ? mb_substr(strtolower($uri).':', 0, 255) : null;
    }

    /**
     * The page's path as the site keeps its addresses, from a document
     * address on the site's own origin; null for any other.
     */
    private static function path(string $uri): ?string
    {
        $origin = self::origin($uri);

        if ($origin === null || $origin !== StaticPages::origin()) {
            return null;
        }

        return mb_substr('/'.PublicRequestPath::normalise((string) parse_url($uri, PHP_URL_PATH)), 0, 512);
    }

    /**
     * The sample a report carries, for an inline script or eval: at most 40
     * characters of it, and where it came from without a query.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @return array{sample?: string, source?: string, line?: int, column?: int}|null
     */
    private static function sample(array $body, string $source): ?array
    {
        $sample = [];
        $text = self::text($body, 'script-sample', 'sample');

        if ($text !== '') {
            $sample['sample'] = mb_substr($text, 0, 40);
        }

        if ($source !== '') {
            $origin = self::origin($source);
            $path = (string) parse_url($source, PHP_URL_PATH);

            if ($origin !== null) {
                $sample['source'] = mb_substr($origin.$path, 0, 512);
            }
        }

        foreach (['line' => ['line-number', 'lineNumber'], 'column' => ['column-number', 'columnNumber']] as $name => $keys) {
            foreach ($keys as $key) {
                if (isset($body[$key]) && is_int($body[$key])) {
                    $sample[$name] = $body[$key];
                    break;
                }
            }
        }

        return $sample === [] ? null : $sample;
    }

    /**
     * Whether none of these strings holds a character of Unicode's other
     * categories (control, format, unassigned, private-use, surrogate) or
     * invalid UTF-8.
     *
     * @param  list<string>  $texts
     */
    private static function plain(array $texts): bool
    {
        foreach ($texts as $text) {
            // preg_match gives false, not 0, for text that isn't valid UTF-8.
            if (preg_match('/\p{C}/u', $text) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function isExtension(string $uri): bool
    {
        return in_array(strtolower((string) parse_url($uri, PHP_URL_SCHEME)), self::EXTENSIONS, true);
    }

    /**
     * An http, https, ws or wss address's scheme, host and port, lower case;
     * null for any other.
     */
    private static function origin(string $uri): ?string
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $ownPort = $port === null || ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme.'://'.strtolower($parts['host']).($ownPort ? '' : ':'.$port);
    }
}
