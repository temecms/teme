<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credit;
use App\Domain\People\Queries\People;
use App\Domain\Site\ListingViews;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\ContentTypeRegistry;

/**
 * Says what a path ending in a feed name means (I-299). An address
 * that exists is never a feed's, so a page called "rss" is a page; a
 * comment feed is gone; a term's, an author's or a type's archive page's
 * old address leads to its current feed; under a shown language's prefix,
 * each feed is that language's (I-3360); any other spelling of a feed leads to the canonical one;
 * and everything else is the public site's to answer, so redirects and the
 * 404 log still apply. It reads only.
 */
final class FeedResolver
{
    /**
     * @param  string  $rawPath  the request's path as it came, with its slashes and case
     */
    public function resolve(string $rawPath): FeedAnswer
    {
        $path = PublicRequestPath::normalise($rawPath);
        $feed = FeedPath::parse($path);

        if ($feed === null) {
            return FeedAnswer::other();
        }

        $languages = app(SiteLanguages::class);

        // Under a hidden language's prefix every feed address is answered as a made-up one there, whatever row it finds (I-3360).
        if ($languages->isHiddenPath($feed->base)) {
            return FeedAnswer::other();
        }

        // The first segment `feed` is the site's own, so nothing else lives at /feed/; an archive page's address is an address too.
        if ($path !== 'feed' && (Paths::find($path) !== null || TypeArchives::at($path) !== null)) {
            return FeedAnswer::other();
        }

        // Under a shown language's prefix, the feeds of that language: its site's, its terms', and its authors' and archive pages' after the prefix.
        [$first, $rest] = explode('/', $feed->base, 2) + [1 => ''];
        $language = $languages->byPrefix($first);
        $prefix = $language === null ? '' : $first;
        $base = $language === null ? $feed->base : $rest;

        if ($base === '') {
            return $this->settle($rawPath, $feed, FeedSubject::site($language, $prefix));
        }

        // No address starts with a first segment the router answers itself (Paths::RESERVED), so such a base finds no row below.
        $row = Paths::find($feed->base);

        // An author's or an archive page's address is the same in every language: found after the prefix, never a term's or an item's there.
        if ($row === null && $language !== null) {
            $row = Paths::find($base);

            if ($row !== null && in_array($row->target_type, ['content', 'term'], true)) {
                return FeedAnswer::other();
            }
        }

        // A type's archive page's feed, before an old address there (I-3304).
        $archive = $row === null || ! $row->is_current ? TypeArchives::at($base) : null;

        if ($archive !== null) {
            return $this->settle($rawPath, $feed, $this->typeSubject($archive, ListingViews::prefixed($language, $base), $language));
        }

        if ($row === null) {
            // WordPress's feed of every comment; nothing lives at "comments", so it is gone.
            return $feed->base === 'comments' ? FeedAnswer::gone() : FeedAnswer::other();
        }

        // An item's comment feed, at its current or an old address.
        if ($row->target_type === 'content') {
            return FeedAnswer::gone();
        }

        $current = $row->is_current ? $row->path : ($row->target_type === TypeArchives::TARGET ? TypeArchives::path((string) $row->target_id) : Paths::current($row->target_type, $row->target_id));

        // A term's path carries its own language's prefix; any other's is found after it.
        if ($current !== null && $row->target_type !== 'term') {
            $current = ListingViews::prefixed($language, (string) $current);
        }

        $subject = $current === null ? null : $this->subject($row->target_type, $row->target_id, $current, $language);

        if ($subject === null) {
            return FeedAnswer::other();
        }

        return $row->is_current
            ? $this->settle($rawPath, $feed, $subject)
            : FeedAnswer::redirect((new FeedPath((string) $current, true))->url());
    }

    /**
     * The feed itself when the request is its one canonical spelling, byte
     * for byte as a client sends it; otherwise a redirect to that spelling.
     */
    private function settle(string $rawPath, FeedPath $feed, FeedSubject $subject): FeedAnswer
    {
        $canonical = (new FeedPath($subject->path, true))->url();

        return $feed->canonical && $rawPath === PublicRequestPath::encode($canonical)
            ? FeedAnswer::feed($subject)
            : FeedAnswer::redirect($canonical);
    }

    private function subject(string $type, string $id, string $path, ?string $language): ?FeedSubject
    {
        // Each kind of Paths::KINDS with a feed below; any other has none here, never someone's.
        if (! Paths::known($type, $id)) {
            return null;
        }

        if ($type === TypeArchives::TARGET) {
            return $this->typeSubject($id, $path, $language);
        }

        if ($type === 'term') {
            $term = Term::query()->find($id);

            // A term's feed is in its own language, a shown one (I-3360).
            return $term === null || ! app(SiteLanguages::class)->isShown($term->language) ? null : new FeedSubject(FeedSubject::TERM, (string) $term->name, $path, self::text($term->description), (string) $term->taxonomy, (string) $term->id, null, null, $term->language);
        }

        // An account by the ID its row holds (Paths::account()), or a person with no account by theirs.
        $account = Paths::account($type, $id);
        $credit = match (true) {
            $account !== null => Credit::account($account),
            $type === Credit::PERSON => Credit::person($id),
            default => null,
        };
        $shown = $credit === null ? null : (app(People::class)->shown([$credit])[$credit->key()] ?? null);

        return $shown === null ? null : new FeedSubject(FeedSubject::AUTHOR, $shown->name, $path, $shown->bio, null, null, $credit, null, $language);
    }

    /**
     * A type's archive page's feed: its plural names the channel, and its key chooses the items.
     */
    private function typeSubject(string $type, string $path, ?string $language): FeedSubject
    {
        return new FeedSubject(FeedSubject::TYPE, app(ContentTypeRegistry::class)->get($type)->plural, $path, null, null, null, null, $type, $language);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
