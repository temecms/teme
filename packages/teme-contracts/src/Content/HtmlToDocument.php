<?php

/**
 * Turns HTML into the Tiptap document Teme stores, with the same converter
 * Teme's importer uses, offered to every plugin with no capability.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * Turns HTML into the Tiptap document Teme stores, with the same converter
 * Teme's importer uses, offered to every plugin with no capability: it
 * reads nothing and writes nothing. A plugin bringing in text written
 * elsewhere, such as a README turned from Markdown into HTML, converts it
 * here and hands the document to ContentWriter.
 *
 * @since 1.3.0
 */
interface HtmlToDocument
{
    /**
     * The document the HTML becomes: `['type' => 'doc', 'content' => [...]]`,
     * with headings, paragraphs, lists, quotes, code, rules, tables and
     * images as the editor holds them. Script, style and unknown markup are
     * left out; links and images are kept at the addresses given.
     *
     * @return array<string, mixed>
     *
     * @since 1.3.0
     */
    public function convert(string $html): array;
}
