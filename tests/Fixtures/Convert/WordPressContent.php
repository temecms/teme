<?php

declare(strict_types=1);

namespace Tests\Fixtures\Convert;

use App\Domain\Import\Convert\BlockConverter;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\KnownMedia;
use App\Domain\Import\Convert\SourceTargets;
use App\Domain\Import\Phases\ImportMessage;

/**
 * The classic converter's test helpers (I-336): a classic chunk as the
 * companion sends one, the converter's answer from an old site at
 * https://old.example, and the nodes tests compare with.
 */
final class WordPressContent
{
    /**
     * A freeform chunk: classic text, and the HTML wpautop() made of it.
     *
     * @return array<string, mixed>
     */
    public static function classic(string $autop): array
    {
        return ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $autop, 'innerContent' => [$autop], 'autop' => $autop];
    }

    /**
     * The converter's answer for these blocks.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, string>  $attachments
     * @param  array<string, string>  $files
     * @param  list<string>  $shortcodes  the shortcodes registered on the old site
     */
    public static function convert(array $blocks, ?SourceTargets $targets = null, ?ConversionItem $item = null, array $attachments = [], array $files = [], array $shortcodes = []): Conversion
    {
        return app(BlockConverter::class)->convert($blocks, new ConversionSite('https://old.example', null, $shortcodes), new KnownMedia($attachments, $files), $targets, $item);
    }

    /**
     * Each message as its code, its words and its context.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function said(Conversion $conversion): array
    {
        return array_map(fn (ImportMessage $message): array => [$message->code, $message->message, $message->context], $conversion->messages);
    }

    /**
     * A paragraph of one run of plain text.
     *
     * @return array<string, mixed>
     */
    public static function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * A paragraph whose words all link to this address.
     *
     * @return array<string, mixed>
     */
    public static function linked(string $text, string $href): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]];
    }

    /**
     * An Image card without its ID, as wpWithoutIds() leaves it.
     *
     * @return array<string, mixed>
     */
    public static function image(string $mediaId, ?string $alt, ?string $caption = null): array
    {
        return ['type' => 'image', 'attrs' => ['mediaId' => $mediaId, 'alt' => $alt, 'decorative' => false, 'caption' => $caption, 'credit' => null, 'width' => 'column']];
    }

    /**
     * An Embed card without its ID.
     *
     * @return array<string, mixed>
     */
    public static function embed(string $url, string $kind, ?string $provider): array
    {
        return ['type' => 'embed', 'attrs' => ['url' => $url, 'kind' => $kind, 'provider' => $provider, 'title' => null, 'html' => null, 'thumbnailMediaId' => null, 'caption' => null]];
    }

    /**
     * An Imported content card without its ID, not yet kept.
     *
     * @param  list<array<string, mixed>>  $markup
     *
     * @return array<string, mixed>
     */
    public static function card(array $markup, string $sourceKind, ?string $sourceName = null): array
    {
        return ['type' => 'imported', 'attrs' => ['markup' => $markup, 'sourceKind' => $sourceKind, 'sourceName' => $sourceName, 'kept' => false]];
    }
}
