<?php

/**
 * The items that have a plain-text `.md` copy (I-713): exactly those the
 * sitemap lists and /llms.txt may name, found by the address the copy is at.
 * The page shown as the home page is `/index.md`. Everything else is left
 * to the public site, which answers it as it would any address.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Llms;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Credits;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class MarkdownPages
{
    /**
     * The path the `.md` route takes, read by the route and by LlmsTxt, so
     * llms.txt never names an item whose copy the route can't answer.
     */
    public const string ROUTE = '[A-Za-z0-9/_-]+';

    /** The name the page shown as the home page has. */
    public const string FRONT = 'index';

    /**
     * Whether an item at this stored path has a `.md` address the route
     * answers: its path fits the route, and `index` is left to the home
     * page while one is chosen.
     */
    public static function answers(string $path, AddressSettings $addresses): bool
    {
        if (preg_match('~^'.self::ROUTE.'$~D', $path) !== 1) {
            return false;
        }

        return $path !== self::FRONT || self::frontPageId($addresses) === '';
    }

    /**
     * The item whose copy is at this path (the route's `{path}`, without
     * `.md`), or null when nothing listed is there: the title, the excerpt
     * when it has one, the date it went live, the names of the people it
     * credits, joined, when it credits anyone (I-3366), its address now, and
     * the published revision's document. Tags the page `content:{id}`, and
     * `person:{id}` for each person it credits.
     *
     * @return array{id: string, title: string, excerpt: string|null, published_at: CarbonImmutable, author: string|null, url: string, document: array<string, mixed>}|null
     */
    public static function find(string $path): ?array
    {
        $addresses = AddressSettings::current();

        if (preg_match('~^'.self::ROUTE.'$~D', $path) !== 1) {
            return null;
        }

        $front = self::frontPageId($addresses);

        if ($path === self::FRONT && $front !== '') {
            $id = $front;
            $url = rtrim(url('/'), '/').'/';
        } else {
            $row = Paths::find($path);

            if ($row === null || ! $row->is_current || $row->target_type !== 'content') {
                return null;
            }

            $id = (string) $row->target_id;
            $url = null;
        }

        // The home page is at /index.md only; its own address is the site's.
        if ($url === null && $id === $front && $front !== '') {
            return null;
        }

        if (app(ListedItems::class)->listed($id) === null) {
            return null;
        }

        $item = DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->where('contents.id', $id)
            ->first(['contents.type', 'contents.published_at', 'content_revisions.title', 'content_revisions.document', 'content_revisions.fields']);

        if ($item === null) {
            return null;
        }

        $type = (string) $item->type;
        $registry = app(ContentTypeRegistry::class);
        $known = $registry->has($type) ? $registry->get($type) : null;

        if ($known === null || ! $known->public || ! $addresses->hasPattern($type) || TitlesAndListing::hidesType($type)) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::content($id));

        // The byline shows each name, so renaming one purges the page; Credits::shown() tags each.
        $names = array_values(array_filter(array_map(fn (object $person): string => trim($person->name), app(Credits::class)->shown([$id])[$id] ?? []), fn (string $name): bool => $name !== ''));

        $fields = (array) json_decode((string) $item->fields, true);
        $excerpt = $fields[BuiltInFields::EXCERPT] ?? null;
        $author = $names === [] ? null : implode(', ', $names);

        return [
            'id' => $id,
            'title' => (string) $item->title,
            'excerpt' => is_string($excerpt) && trim($excerpt) !== '' ? trim($excerpt) : null,
            'published_at' => CarbonImmutable::parse((string) $item->published_at),
            'author' => $author,
            'url' => $url ?? rtrim(url('/'), '/').Addresses::toUrl($path, $type),
            'document' => (array) json_decode((string) $item->document, true),
        ];
    }

    /**
     * The copy's text: the title, the excerpt quoted when it has one, the
     * line with the date it went live in the site's zone, the author and the
     * page's address, then the body; one newline at the end.
     *
     * @param  array{id: string, title: string, excerpt: string|null, published_at: CarbonImmutable, author: string|null, url: string, document: array<string, mixed>}  $page
     */
    public static function text(array $page): string
    {
        $date = $page['published_at']->setTimezone(app(SettingsReader::class)->string('general', 'timezone'))->format('Y-m-d');
        $line = implode(' · ', array_filter(['Published '.$date, $page['author'] === null ? null : MarkdownDocument::words($page['author']), $page['url']]));
        $blocks = ['# '.MarkdownDocument::words($page['title'])];

        if ($page['excerpt'] !== null) {
            $blocks[] = '> '.MarkdownDocument::words($page['excerpt']);
        }

        $blocks[] = $line;
        $body = MarkdownDocument::of($page['document']);

        if ($body !== '') {
            $blocks[] = $body;
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * The home page's ID while one is chosen, '' otherwise.
     */
    private static function frontPageId(AddressSettings $addresses): string
    {
        return $addresses->frontShows === AddressSettings::FRONT_PAGE ? $addresses->frontPageId : '';
    }
}
