<?php

/**
 * Cleans insertion markup like site code (I-064, I-697): in every namespace,
 * SVG and MathML included, drops scripts without src, templates, every on*
 * attribute, every data-teme-* attribute (core's own markers), srcdoc, and
 * addresses that run script or draw a page; keeps script src,
 * iframe and img, and collects their https origins for the page's policy.
 * An inline script is kept only when its exact text was approved for the
 * plugin that gave it, while the host allows site code, and its hash goes
 * to the page's policy (docs/plans/plugin-sockets-2.md, B4).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodePolicy;
use Dom\Element;
use Dom\HTMLDocument;

final class InsertionCleaner
{
    /** Attributes that hold an address a browser follows or loads. */
    private const array ADDRESSES = ['href', 'src', 'xlink:href', 'action', 'formaction'];

    /** Address schemes that run script or draw a page of their own. */
    private const array UNSAFE_SCHEMES = ['javascript:', 'data:text/html'];

    /** SVG elements that can set another attribute, such as an href, as they animate. */
    private const array ANIMATIONS = ['animate', 'set'];

    /**
     * Elements dropped with everything in them, in any namespace: a browser
     * reads them differently from the cleaner (raw text with scripting on, a
     * template's inert content brought to life, MathML that breaks out of
     * foreign content), so markup inside them could run once drawn.
     */
    private const array DROPPED = ['template', 'noscript', 'noembed', 'noframes', 'mglyph', 'malignmark'];

    /** The prefix of the attributes core's own scripts act on, which plugin markup never carries. */
    private const string CORE_HOOKS = 'data-teme-';

    /** How many times the cleaner reads its own output again before it gives up on markup that keeps changing. */
    private const int PASSES = 4;

    /**
     * Cleans the markup, then reads the result again as a browser would and
     * cleans that, until a pass changes nothing; markup that still changes
     * after the last pass is refused whole.
     *
     * @param  list<string>  $approved  the hashes of the inline scripts approved for the plugin that gave it, as PagePolicy::hash() gives them
     *
     * @return array{html: string, origins: array<string, list<string>>, scripts: list<string>}
     */
    public static function clean(string $html, array $approved = []): array
    {
        $approved = SiteCode::disallowed() ? [] : array_fill_keys($approved, true);
        $stable = false;
        $scripts = [];

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            [$cleaned, $scripts] = self::pass($html, $approved);

            if ($cleaned === $html) {
                $stable = true;

                break;
            }

            $html = $cleaned;
        }

        if (! $stable) {
            return ['html' => '', 'origins' => [], 'scripts' => []];
        }

        $origins = SiteCodePolicy::of($html, [])['origins'];
        $kept = [];

        foreach (['script-src', 'frame-src', 'img-src'] as $directive) {
            if (isset($origins[$directive])) {
                $kept[$directive] = $origins[$directive];
            }
        }

        return ['html' => $html, 'origins' => $kept, 'scripts' => $scripts];
    }

    /**
     * One reading of the markup: parsed as a document body, cleaned, and
     * written back.
     *
     * @param  array<string, true>  $approved
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function pass(string $html, array $approved): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html, LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if ($body === null) {
            return ['', []];
        }

        $scripts = [];

        foreach ([...$body->getElementsByTagName('*')] as $element) {
            if ($element->parentNode !== null && in_array(strtolower($element->localName), self::DROPPED, true)) {
                $element->remove();
            }
        }

        foreach ([...$body->getElementsByTagName('*')] as $element) {
            if ($element->parentNode === null) {
                continue;
            }

            $kept = self::approvedScript($element, $approved);

            if ($kept !== null) {
                $scripts[$kept] = true;

                continue;
            }

            self::cleanElement($element);
        }

        $cleaned = '';

        foreach ($body->childNodes as $child) {
            $cleaned .= $document->saveHtml($child);
        }

        return [$cleaned, array_keys($scripts)];
    }

    /**
     * The hash of this element when it is an inline script whose exact text
     * was approved, kept as written with no attribute but its type; null for
     * anything else. The text hashed is the parser's, as a browser hashes it.
     *
     * @param  array<string, true>  $approved
     */
    public static function approvedScript(Element $element, array $approved): ?string
    {
        if ($approved === [] || strtolower($element->localName) !== 'script' || $element->namespaceURI !== 'http://www.w3.org/1999/xhtml' || $element->hasAttribute('src')) {
            return null;
        }

        $type = strtolower(trim((string) $element->getAttribute('type')));
        $hash = PagePolicy::hash(str_replace(["\r\n", "\r"], "\n", (string) $element->textContent));

        if (($type !== '' && $type !== 'text/javascript') || ! isset($approved[$hash])) {
            return null;
        }

        foreach ([...$element->attributes] as $attribute) {
            if (strtolower($attribute->name) !== 'type') {
                $element->removeAttributeNode($attribute);
            }
        }

        return $hash;
    }

    private static function cleanElement(Element $element): void
    {
        $name = strtolower($element->localName);

        // A template's content is inert until core copies it, and core's own scripts copy what they find: none is kept.
        if ($name === 'script' && ! $element->hasAttribute('src') || $name === 'template') {
            $element->remove();

            return;
        }

        if (in_array($name, self::ANIMATIONS, true) && in_array(strtolower(trim((string) $element->getAttribute('attributeName'))), ['href', 'xlink:href'], true)) {
            $element->remove();

            return;
        }

        foreach ([...$element->attributes] as $attribute) {
            $attributeName = strtolower($attribute->name);

            // data-teme-* is core's own markup: a placeholder or a wrapper its scripts act on, never a plugin's.
            if (str_starts_with($attributeName, 'on') || $attributeName === 'srcdoc' || str_starts_with($attributeName, self::CORE_HOOKS)
                || (in_array($attributeName, self::ADDRESSES, true) && self::unsafe($attribute->value))) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Whether the address runs script or draws a page: compared as a browser
     * reads it, without the spaces, tabs, newlines and controls it ignores.
     */
    private static function unsafe(string $address): bool
    {
        $address = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $address));

        return array_any(self::UNSAFE_SCHEMES, fn (string $scheme): bool => str_starts_with($address, $scheme));
    }
}
