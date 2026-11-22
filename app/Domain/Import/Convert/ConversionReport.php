<?php

/**
 * What one conversion left out or changed, counted by code and by block or
 * element name, and given as import messages in the report's words (I-345).
 * It holds no article text: names, file names and hosts only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Import\Phases\ImportMessage;
use LogicException;

final class ConversionReport
{
    /** The most files or hosts a message names. */
    private const int NAMED = 10;

    /** The longest file name or host a message names, so ten stay within a message's 1,000 characters. */
    private const int LONGEST = 60;

    /** @var array<string, array{code: string, name: string, count: int, items: list<string>}> */
    private array $counts = [];

    /**
     * Counts one more of this code for this name (or this many), with the
     * file or host it names, if any.
     */
    public function count(string $code, string $name = '', ?string $item = null, int $times = 1): void
    {
        $key = $code."\n".$name;
        $entry = $this->counts[$key] ?? ['code' => $code, 'name' => $name, 'count' => 0, 'items' => []];
        $entry['count'] += $times;
        $item = $item === null ? '' : mb_substr($item, 0, self::LONGEST);

        if ($item !== '' && ! in_array($item, $entry['items'], true)) {
            $entry['items'][] = $item;
        }

        $this->counts[$key] = $entry;
    }

    /**
     * The messages, one per code and name, in the order each was first
     * counted.
     *
     * @return list<ImportMessage>
     */
    public function messages(): array
    {
        return array_values(array_map(self::message(...), $this->counts));
    }

    /**
     * @param  array{code: string, name: string, count: int, items: list<string>}  $entry
     */
    private static function message(array $entry): ImportMessage
    {
        ['code' => $code, 'name' => $name, 'count' => $count, 'items' => $items] = $entry;
        // A plugin's block name can be long; a message holds at most 1,000 characters.
        $name = mb_substr($name, 0, 100);
        $one = $count === 1;
        $named = $items === [] ? '' : ': '.self::named($items);
        $pages = $count + 1;

        $words = match ($code) {
            'block.dynamic' => $one
                ? "Left out 1 {$name} block: WordPress drew it when the page loaded, and on Teme the theme does that job."
                : "Left out {$count} {$name} blocks: WordPress drew them when the page loaded, and on Teme the theme does that job.",
            'block.contents-list' => $one
                ? __('Left out 1 table of contents (:name): Teme and its theme don’t draw one; a table-of-contents plugin can.', ['name' => $name])
                : __('Left out :count tables of contents (:name): Teme and its theme don’t draw them; a table-of-contents plugin can.', ['count' => $count, 'name' => $name]),
            'block.page-break' => "Joined the article’s {$pages} pages into one: Teme shows each article on a single page.",
            'block.flattened' => $one
                ? "Kept what 1 {$name} block held, in order, without its layout."
                : "Kept what {$count} {$name} blocks held, in order, without their layout.",
            'block.imported' => $one
                ? "Kept 1 {$name} block as imported content: Teme can’t turn it into its own blocks."
                : "Kept {$count} {$name} blocks as imported content: Teme can’t turn them into its own blocks.",
            'imported.too-large' => $one
                ? "Converted 1 piece of imported content from {$name} into Teme’s own blocks: it was too large for an Imported content card."
                : "Converted {$count} pieces of imported content from {$name} into Teme’s own blocks: they were too large for an Imported content card.",
            'imported.removed' => $one
                ? "Left out 1 {$name} element from imported content: the card doesn’t keep it."
                : "Left out {$count} {$name} elements from imported content: the card doesn’t keep them.",
            'imported.styles' => $one
                ? 'Left out the inline styles of 1 element in imported content: Teme’s theme styles it.'
                : "Left out the inline styles of {$count} elements in imported content: Teme’s theme styles them.",
            'block.formula' => $one
                ? 'Kept 1 formula as its LaTeX source in a code block: Teme can’t show formulas yet.'
                : "Kept {$count} formulas as their LaTeX source in code blocks: Teme can’t show formulas yet.",
            'block.background' => $one
                ? "Left out the background image of 1 {$name} block; what it held is kept."
                : "Left out the background images of {$count} {$name} blocks; what they held is kept.",
            'block.too-deep' => $one
                ? 'Kept 1 list or quote nested too deeply for Teme as plain paragraphs, in order.'
                : "Kept {$count} lists or quotes nested too deeply for Teme as plain paragraphs, in order.",
            'image.missing' => $one
                ? "Left out 1 image that isn’t in Teme’s library{$named}."
                : "Left out {$count} images that aren’t in Teme’s library{$named}.",
            'image.outside' => $one
                ? "Linked to 1 image on another site instead of showing it{$named}."
                : "Linked to {$count} images on other sites instead of showing them{$named}.",
            'file.missing' => $one
                ? "Kept the words of 1 link to a file that isn’t in Teme’s library, without the link{$named}."
                : "Kept the words of {$count} links to files that aren’t in Teme’s library, without the links{$named}.",
            'image.link' => $one
                ? 'Left out the link on 1 image: Teme’s images don’t link anywhere.'
                : "Left out the links on {$count} images: Teme’s images don’t link anywhere.",
            'caption.links' => $one
                ? 'Kept the words of 1 link in a caption, without the link: Teme’s captions are plain text.'
                : "Kept the words of {$count} links in captions, without the links: Teme’s captions are plain text.",
            'gallery.captions' => $one
                ? 'Left out 1 caption on a gallery’s image: a Teme gallery has one caption.'
                : "Left out {$count} captions on galleries’ images: a Teme gallery has one caption.",
            'embed.no-address' => $one
                ? 'Left out 1 embed with no web address.'
                : "Left out {$count} embeds with no web address.",
            'link.removed' => $one
                ? 'Kept the words of 1 link whose address Teme can’t keep, without the link.'
                : "Kept the words of {$count} links whose addresses Teme can’t keep, without the links.",
            'links.other-host' => $one
                ? "Converted 1 link from another address of this site ({$name})."
                : "Converted {$count} links from another address of this site ({$name}).",
            'images.other-host' => $one
                ? "Converted 1 image from another address of this site ({$name})."
                : "Converted {$count} images from another address of this site ({$name}).",
            'media.missing' => $one
                ? "Left out 1 sound or video that isn’t in Teme’s library{$named}."
                : "Left out {$count} sounds or videos that aren’t in Teme’s library{$named}.",
            'media.outside' => $one
                ? "Linked to 1 sound or video on another site instead of playing it{$named}."
                : "Linked to {$count} sounds or videos on other sites instead of playing them{$named}.",
            'video.playback' => $one
                ? 'Left out the autoplay, loop or mute setting of 1 video: Teme plays a video when the reader asks, or loops it silently.'
                : "Left out the autoplay, loop or mute settings of {$count} videos: Teme plays a video when the reader asks, or loops it silently.",
            'video.tracks' => $one
                ? 'Left out 1 text track of a video that Teme can’t keep: a metadata track, or one without its file or language.'
                : "Left out {$count} text tracks of videos that Teme can’t keep: metadata tracks, or ones without their file or language.",
            'html.event-attributes' => $one
                ? 'Left out the scripts on 1 element: Teme doesn’t run scripts in an article.'
                : "Left out the scripts on {$count} elements: Teme doesn’t run scripts in an article.",
            'gallery.empty' => $one
                ? 'Left out 1 gallery shortcode with no images Teme can find.'
                : "Left out {$count} gallery shortcodes with no images Teme can find.",
            'shortcode.unwrapped' => $one
                ? "Kept the words inside 1 [{$name}] shortcode without it: its plugin or theme no longer runs on the old site."
                : "Kept the words inside {$count} [{$name}] shortcodes without them: its plugin or theme no longer runs on the old site.",
            'shortcode.removed' => $one
                ? "Left out 1 [{$name}] shortcode: its plugin or theme no longer runs on the old site, so readers saw it as bracketed text."
                : "Left out {$count} [{$name}] shortcodes: its plugin or theme no longer runs on the old site, so readers saw them as bracketed text.",
            'shortcode.imported' => $one
                ? "Kept what 1 [{$name}] shortcode drew on the old site as imported content: its plugin still runs there, and Teme has no match for it."
                : "Kept what {$count} [{$name}] shortcodes drew on the old site as imported content: its plugin still runs there, and Teme has no match for it.",
            'shortcode.inline' => $one
                ? "Kept the words 1 [{$name}] shortcode drew on the old site, in their line: its plugin still runs there."
                : "Kept the words {$count} [{$name}] shortcodes drew on the old site, in their lines: its plugin still runs there.",
            'shortcode.unrendered' => $one
                ? "Left out 1 [{$name}] shortcode, keeping any words inside it: its plugin still runs on the old site, but the export didn’t carry what it drew."
                : "Left out {$count} [{$name}] shortcodes, keeping any words inside them: its plugin still runs on the old site, but the export didn’t carry what they drew.",
            'html.removed' => $one
                ? "Left out 1 {$name} element, which Teme doesn’t keep in an article."
                : "Left out {$count} {$name} elements, which Teme doesn’t keep in an article.",
            default => throw new LogicException("No words for the import message [{$code}]."),
        };

        $context = match ($code) {
            'block.dynamic', 'block.contents-list', 'block.flattened', 'block.imported', 'imported.too-large', 'block.background', 'shortcode.unwrapped', 'shortcode.removed', 'shortcode.imported', 'shortcode.inline', 'shortcode.unrendered', 'links.other-host', 'images.other-host' => ['name' => $name, 'count' => $count],
            'html.removed', 'imported.removed' => ['element' => $name, 'count' => $count],
            'image.missing', 'file.missing', 'media.missing' => ['count' => $count, 'files' => array_slice($items, 0, self::NAMED)],
            'image.outside', 'media.outside' => ['count' => $count, 'hosts' => array_slice($items, 0, self::NAMED)],
            default => ['count' => $count],
        };

        return new ImportMessage($code, $words, $context);
    }

    /**
     * The first ten, joined, then how many more.
     *
     * @param  list<string>  $items
     */
    private static function named(array $items): string
    {
        $shown = implode(', ', array_slice($items, 0, self::NAMED));
        $more = count($items) - self::NAMED;

        return $more > 0 ? "{$shown} and {$more} more" : $shown;
    }
}
