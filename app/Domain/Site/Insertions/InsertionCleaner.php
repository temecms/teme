<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\SiteCode\SiteCodePolicy;
use Dom\HTMLDocument;
use Dom\HTMLElement;

/**
 * Cleans insertion markup like site code (I-064, I-697): drops inline
 * script bodies and every on* attribute; keeps script src, iframe and img,
 * and collects their https origins for the page's content security policy.
 */
final class InsertionCleaner
{
    /**
     * @return array{html: string, origins: array<string, list<string>>}
     */
    public static function clean(string $html): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html, LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if ($body === null) {
            return ['html' => '', 'origins' => []];
        }

        foreach ([...$body->getElementsByTagName('*')] as $element) {
            if (! $element instanceof HTMLElement) {
                continue;
            }

            foreach ([...$element->attributes] as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $element->removeAttribute($attribute->name);
                }
            }

            if (strtolower($element->localName) === 'script' && ! $element->hasAttribute('src')) {
                $element->remove();
            }
        }

        $cleaned = '';

        foreach ($body->childNodes as $child) {
            $cleaned .= $document->saveHtml($child);
        }

        $origins = SiteCodePolicy::of($cleaned, [])['origins'];
        $kept = [];

        foreach (['script-src', 'frame-src', 'img-src'] as $directive) {
            if (isset($origins[$directive])) {
                $kept[$directive] = $origins[$directive];
            }
        }

        return ['html' => $cleaned, 'origins' => $kept];
    }
}
