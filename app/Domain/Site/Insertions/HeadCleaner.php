<?php

/**
 * Cleans what a plugin puts in a page's head, which holds less than the
 * body: a script loaded from an https address, a meta name the page doesn't
 * already own, a preconnect or dns-prefetch link to an https origin, and an
 * inline script whose exact text was approved for the plugin. Everything
 * else is dropped, whole (docs/plans/plugin-sockets-2.md, B2).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodePolicy;
use Dom\Element;
use Dom\HTMLDocument;

final class HeadCleaner
{
    /** Meta names core, the theme or the page's settings own. */
    public const array OWNED_META = ['robots', 'googlebot', 'description', 'viewport', 'referrer', 'theme-color', 'color-scheme', 'generator', 'charset', 'http-equiv'];

    /** What each kept element may carry. */
    private const array ATTRIBUTES = [
        'script' => ['src', 'async', 'defer', 'crossorigin', 'type'],
        'meta' => ['name', 'content'],
        'link' => ['rel', 'href', 'crossorigin'],
    ];

    /**
     * @param  list<string>  $approved  the hashes of the inline scripts approved for the plugin that gave it
     *
     * @return array{html: string, origins: array<string, list<string>>, scripts: list<string>}
     */
    public static function clean(string $html, array $approved = []): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html, LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if ($body === null) {
            return ['html' => '', 'origins' => [], 'scripts' => []];
        }

        $approved = SiteCode::disallowed() ? [] : array_fill_keys($approved, true);
        $kept = [];
        $scripts = [];

        foreach ([...$body->childNodes] as $node) {
            if (! $node instanceof Element) {
                continue;
            }

            $hash = InsertionCleaner::approvedScript($node, $approved);

            if ($hash !== null) {
                $scripts[$hash] = true;
                $kept[] = $document->saveHtml($node);

                continue;
            }

            if (self::keep($node)) {
                $kept[] = $document->saveHtml($node);
            }
        }

        $cleaned = implode("\n", $kept);
        $origins = SiteCodePolicy::of($cleaned, [])['origins'];

        return [
            'html' => $cleaned,
            'origins' => isset($origins['script-src']) ? ['script-src' => $origins['script-src']] : [],
            'scripts' => array_keys($scripts),
        ];
    }

    /**
     * Whether this top-level element may stay in the head, with only the
     * attributes its kind may carry left on it.
     */
    private static function keep(Element $element): bool
    {
        if ($element->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
            return false;
        }

        $name = strtolower($element->localName);

        $allowed = match ($name) {
            'script' => self::https((string) $element->getAttribute('src'))
                && in_array(strtolower(trim((string) $element->getAttribute('type'))), ['', 'module'], true),
            'meta' => ! $element->hasAttribute('http-equiv') && ! $element->hasAttribute('charset')
                && ($meta = strtolower(trim((string) $element->getAttribute('name')))) !== ''
                && ! in_array($meta, self::OWNED_META, true)
                && $element->hasAttribute('content'),
            'link' => in_array(strtolower(trim((string) $element->getAttribute('rel'))), ['preconnect', 'dns-prefetch'], true)
                && self::https((string) $element->getAttribute('href')),
            default => false,
        };

        if (! $allowed) {
            return false;
        }

        foreach ([...$element->attributes] as $attribute) {
            if (! in_array(strtolower($attribute->name), self::ATTRIBUTES[$name], true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        // A script with an address runs the address, never its own text.
        if ($name === 'script') {
            $element->textContent = '';
        }

        return true;
    }

    /** Whether the address is https, or protocol-relative, which an https page loads over https. */
    private static function https(string $address): bool
    {
        $address = strtolower(trim($address));

        return (str_starts_with($address, 'https://') || str_starts_with($address, '//')) && strlen($address) > 8;
    }
}
