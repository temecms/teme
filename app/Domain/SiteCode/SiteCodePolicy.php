<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Plugins\HostPattern;
use App\Domain\Site\Policy\PagePolicy;
use Dom\HTMLDocument;

/**
 * What one entry of site code needs from the public site's policy
 * (I-3375): the hash of each inline script and each on* handler, as a
 * browser hashes them once it has parsed the code, and the https origins
 * its tags load from, by directive, with those its administrator declared.
 * Worked out from the code as saved, never from a drawn page.
 */
final class SiteCodePolicy
{
    /** Each tag that loads from an address, the attribute that holds it, and the directive it falls under. */
    private const array LOADS = [
        'script' => ['src', 'script-src'],
        'img' => ['src', 'img-src'],
        'iframe' => ['src', 'frame-src'],
        'video' => ['src', 'media-src'],
        'audio' => ['src', 'media-src'],
        'source' => ['src', 'media-src'],
        'form' => ['action', 'form-action'],
    ];

    /**
     * @param  list<string>  $declared  host names, already checked
     *
     * @return array{scripts: list<string>, handlers: list<string>, origins: array<string, list<string>>}
     */
    public static function of(string $code, array $declared): array
    {
        // HTML's own parser: it turns CR LF into LF and decodes attributes, as a browser does before it hashes.
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$code, LIBXML_NOERROR, 'UTF-8');
        $scripts = [];
        $handlers = [];
        $origins = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $handlers[PagePolicy::hash($attribute->value)] = true;
                }
            }

            $tag = strtolower($element->localName);

            if ($tag === 'script' && ! $element->hasAttribute('src')) {
                $scripts[PagePolicy::hash((string) $element->textContent)] = true;

                continue;
            }

            [$holder, $directive] = $tag === 'link' && preg_match('/(^|\s)stylesheet(\s|$)/i', (string) $element->getAttribute('rel')) === 1
                ? ['href', 'style-src']
                : (self::LOADS[$tag] ?? [null, null]);
            $origin = $holder === null ? null : self::httpsOrigin((string) $element->getAttribute($holder));

            if ($origin !== null && $directive !== null) {
                $origins[$directive][$origin] = true;
            }
        }

        foreach ($declared as $host) {
            if (preg_match(HostPattern::PATTERN, $host) !== 1) {
                continue;
            }

            foreach (PagePolicy::DECLARED as $directive) {
                $origins[$directive]['https://'.$host] = true;
            }
        }

        $listed = [];

        foreach ($origins as $directive => $set) {
            $names = array_keys($set);
            sort($names);
            $listed[$directive] = $names;
        }

        ksort($listed);

        return ['scripts' => array_keys($scripts), 'handlers' => array_keys($handlers), 'origins' => $listed];
    }

    /**
     * An address's origin when it is https, or protocol-relative, which a
     * site served over https loads over https, and its host a plain host
     * name (no wildcard), with the port parse_url() read as a number; null
     * for any other, never repaired: parse_url() leaves spaces, quotes, ; and
     * , in a host.
     */
    private static function httpsOrigin(string $url): ?string
    {
        $url = trim($url);
        $url = str_starts_with($url, '//') ? 'https:'.$url : $url;
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (preg_match(HostPattern::PATTERN, $host) !== 1 || str_starts_with($host, '*.')) {
            return null;
        }

        return 'https://'.$host.($port !== null ? ':'.$port : '');
    }
}
