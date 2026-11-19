<?php

/**
 * A feed as RSS 2.0 (I-299): the channel and its items, with the content,
 * dc, atom and media namespaces, written element by element so every value
 * passes through Xml. The elements and their order are WordPress's, less
 * what Teme drops and plus the featured image's media:content.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

final class Rss2 implements FeedFormat
{
    public const string NAME = 'rss2';

    public const string TYPE = 'application/rss+xml';

    public function name(): string
    {
        return self::NAME;
    }

    public function contentType(): string
    {
        return self::TYPE.'; charset=UTF-8';
    }

    public function render(Feed $feed): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">'."\n"
            ."<channel>\n"
            .'  <title>'.Xml::text($feed->title)."</title>\n"
            .'  <atom:link href="'.Xml::attribute($feed->selfUrl).'" rel="self" type="'.self::TYPE.'"/>'."\n"
            .$this->links($feed)
            .'  <link>'.Xml::text($feed->link)."</link>\n"
            .'  <description>'.Xml::text($feed->description)."</description>\n";

        if ($feed->updated !== null) {
            $xml .= '  <lastBuildDate>'.Xml::date($feed->updated)."</lastBuildDate>\n";
        }

        $xml .= '  <language>'.Xml::text($feed->language)."</language>\n";

        foreach ($feed->items as $item) {
            $xml .= $this->item($item);
        }

        return $xml."</channel>\n</rss>\n";
    }

    /**
     * The links plugins add, straight after the feed's own, every value escaped.
     */
    private function links(Feed $feed): string
    {
        $xml = '';

        foreach ($feed->links as $link) {
            $xml .= '  <atom:link rel="'.Xml::attribute($link['rel']).'" href="'.Xml::attribute($link['href']).'"/>'."\n";
        }

        return $xml;
    }

    private function item(FeedItem $item): string
    {
        $xml = "  <item>\n"
            .'    <title>'.Xml::text($item->title)."</title>\n"
            .'    <link>'.Xml::text($item->url)."</link>\n";

        // One for each person it credits, in order, as WordPress repeats it; an item given only its author, that one.
        foreach ($item->authors !== [] ? $item->authors : array_filter([$item->author], fn (?string $name): bool => $name !== null && trim($name) !== '') as $author) {
            $xml .= '    <dc:creator>'.Xml::text($author)."</dc:creator>\n";
        }

        $xml .= '    <pubDate>'.Xml::date($item->published)."</pubDate>\n";

        foreach ($item->categories as $name) {
            $xml .= '    <category>'.Xml::text($name)."</category>\n";
        }

        $xml .= '    <guid isPermaLink="false">'.Xml::text($item->id)."</guid>\n"
            .'    <description>'.Xml::text($item->summary)."</description>\n";

        if ($item->html !== null && trim($item->html) !== '') {
            $xml .= '    <content:encoded>'.Xml::cdata($item->html)."</content:encoded>\n";
        }

        if ($item->image !== null) {
            $xml .= '    <media:content url="'.Xml::attribute($item->image->url).'" medium="image"'
                .($item->image->type === null ? '' : ' type="'.Xml::attribute($item->image->type).'"')
                .' width="'.$item->image->width.'" height="'.$item->image->height."\"/>\n";
        }

        return $xml."  </item>\n";
    }
}
