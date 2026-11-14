<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\References;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Credits;
use App\Domain\Seo\WholeSite;
use App\Domain\Site\SiteLanguage;
use Carbon\CarbonImmutable;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Builds a Feed from a subject (I-299): the channel from the site's settings and the subject, the items from their published revisions, and records for the page cache everything the feed shows. Reads only.
 */
final class FeedBuilder
{
    public const string TAG = 'feed';

    public function __construct(
        private FeedItems $items,
        private FeedBody $body,
        private FeedUrls $urls,
        private FeedImages $images,
        private CardMedia $media,
        private SettingsReader $settings,
        private SiteLanguage $language,
    ) {}

    /**
     * The subject's feed: its newest items, as many as the setting allows.
     */
    public function build(FeedSubject $subject): Feed
    {
        $record = app(RenderRecord::class);
        $types = $this->items->types($subject);
        $record->tag(self::TAG, ...$this->subjectTags($subject, $types));

        $ids = $this->items->ids($subject, FeedsSection::items());
        $rows = $this->items->rows($ids);
        $paths = $this->items->paths($ids);
        $record->tag(...array_map(PageTags::content(...), $ids));

        $documents = [];
        $fields = [];
        $termIds = [];
        $mediaIds = [];

        foreach ($ids as $id) {
            if (! isset($rows[$id], $paths[$id])) {
                continue;
            }

            $documents[$id] = (array) json_decode((string) $rows[$id]->document, true);
            $fields[$id] = (array) json_decode((string) $rows[$id]->fields, true);
            $termIds[$id] = array_values(array_map('strval', (array) json_decode((string) $rows[$id]->terms, true)));

            foreach (References::of($documents[$id]) as $reference) {
                if ($reference->kind === 'media') {
                    $mediaIds[] = $reference->id;
                }
            }

            $featured = (array) ($fields[$id][BuiltInFields::FEATURED_IMAGE] ?? []);

            if (is_string($featured['id'] ?? null)) {
                $mediaIds[] = $featured['id'];
            }
        }

        // Every picture the feed names, in one statement; each item's own reads are then from memory.
        $media = $this->media->forIds($mediaIds);

        // Each item's credits, by name, in one statement; each person named is tagged there (I-3366).
        $authors = app(Credits::class)->shown(array_map(strval(...), array_keys($documents)));
        $terms = $this->items->terms(array_values(array_unique(array_merge(...array_values($termIds) ?: [[]]))));
        $record->tag(...array_map(PageTags::term(...), array_keys($terms)));

        $fullText = FeedsSection::fullText();
        $items = [];
        $updated = null;

        foreach ($documents as $id => $document) {
            $row = $rows[$id];
            $published = CarbonImmutable::parse((string) $row->published_at);
            $revised = CarbonImmutable::parse((string) $row->revised_at);
            $updated = max(array_filter([$updated, $published, $revised]));
            $names = array_values(array_unique(array_filter(array_map(fn (string $termId): ?string => $terms[$termId] ?? null, $termIds[$id]))));
            $written = $fields[$id][BuiltInFields::EXCERPT] ?? null;
            $html = $fullText ? $this->body->html($document) : null;
            $featured = (array) ($fields[$id][BuiltInFields::FEATURED_IMAGE] ?? []);
            $picture = is_string($featured['id'] ?? null) && isset($media[$featured['id']]) ? $this->images->variant($media[$featured['id']], FeedImages::LEAD_WIDTH) : null;

            $credited = array_values(array_filter(array_map(fn (object $person): string => trim($person->name), $authors[(string) $id] ?? []), fn (string $name): bool => $name !== ''));

            $items[] = new FeedItem(
                $this->guid($id, $row->guid === null ? null : (string) $row->guid),
                (string) $row->title,
                $this->urls->origin().Addresses::toUrl($paths[$id], (string) $row->type),
                $credited[0] ?? null,
                $published,
                $names,
                is_string($written) && trim($written) !== '' ? trim($written) : Excerpt::of($document),
                $html === null || trim($html) === '' ? null : $html,
                $picture === null ? null : new FeedImage($picture['url'], $picture['type'], $picture['width'], $picture['height']),
                $credited,
            );
        }

        $site = $this->settings->string('general', 'site_title');
        $description = collect([$subject->description, $this->settings->string('general', 'tagline'), $site])
            ->first(fn (?string $text): bool => $text !== null && trim($text) !== '');

        return new Feed(
            $subject->kind === FeedSubject::SITE ? $site : "{$subject->name} – {$site}",
            $subject->kind === FeedSubject::SITE ? $this->urls->origin().'/' : $this->urls->origin().Addresses::toUrl($subject->path),
            $this->urls->origin().(new FeedPath($subject->path, true))->url(),
            (string) $description,
            $this->language->tag(),
            $updated,
            $items,
            WholeSite::discouraged() ? 'noindex, nofollow' : 'noindex, follow',
        );
    }

    /**
     * The page-cache tags of what the feed is of: a term's or an author's own,
     * the ticked types' lists for the site.
     *
     * @param  list<string>  $types
     *
     * @return list<string>
     */
    private function subjectTags(FeedSubject $subject, array $types): array
    {
        return match ($subject->kind) {
            FeedSubject::TERM => [PageTags::filedUnder((string) $subject->taxonomy, (string) $subject->termId), PageTags::term((string) $subject->termId)],
            FeedSubject::AUTHOR => [PageTags::author((string) $subject->credit?->id), PageTags::person((string) $subject->credit?->id)],
            FeedSubject::TYPE => [PageTags::listOf((string) $subject->type)],
            default => array_map(PageTags::listOf(...), $types),
        };
    }

    /**
     * The identifier a feed reader remembers an item by: the content ID, which no slug, pattern or domain change touches.
     */
    private function guid(string $id, ?string $imported): string
    {
        // An imported item keeps the guid WordPress gave it, so its readers see nothing new (I-922).
        return $imported !== null && trim($imported) !== '' ? $imported : 'teme:content/'.$id;
    }
}
