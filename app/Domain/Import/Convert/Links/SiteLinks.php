<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Links;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\Convert\Media\MediaTargets;
use App\Domain\Import\Convert\SourceTargets;

/**
 * What a link's address becomes when an item converts (I-1702): an address
 * on the old site becomes a link by ID to what the import made of it, or
 * its path for the second pass when the import hasn't reached it yet
 * (I-333); any other address is kept as Teme keeps one. Never the old
 * site's own address.
 */
final class SiteLinks
{
    /** The longest address a link keeps (DocumentSchema::linkAttributes). */
    private const int LONGEST = 2048;

    /** WordPress's query variables that name a term by its ID, each with its taxonomy. */
    private const array TERM_IDS = [['cat', 'category'], ['tag_id', 'post_tag']];

    /** WordPress's query variables that name a term by its slug, each with its taxonomy. */
    private const array TERM_SLUGS = [['category_name', 'category'], ['tag', 'post_tag']];

    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly ConversionSite $site,
        private readonly MediaTargets $media,
        private readonly SourceTargets $targets,
        private readonly ConversionReport $report,
    ) {}

    /**
     * The address a link keeps, or null when the link goes and its words
     * stay: said as file.missing for a file Teme doesn't have, otherwise as
     * link.removed.
     */
    public function href(string $href): ?string
    {
        $href = trim($href);

        if (preg_match('#^//[^/\s]#', $href) === 1) {
            $href = 'https:'.$href;
        }

        if ($href === '' || strlen($href) > self::LONGEST) {
            $this->report->count('link.removed');

            return null;
        }

        $file = $this->site->uploadsKey($href);

        if ($file !== null) {
            return $this->file($href, $file);
        }

        $page = $this->site->pageOf($href);
        $kept = $page === null ? (DocumentSchema::allowsHref($href) ? $href : null) : $this->page($page, $href);

        if ($kept === null) {
            $this->report->count('link.removed');
        }

        return $kept;
    }

    /**
     * The article an address on the old site leads to, or null: a button
     * holds an article or an outside address only.
     */
    public function content(string $href): ?string
    {
        $href = trim($href);
        $page = $this->site->uploadsKey($href) === null ? $this->site->pageOf($href) : null;
        $target = $page === null ? null : $this->target($page['path'], $page['query']);

        return $target !== null && $target['kind'] === 'content' ? strtolower($target['id']) : null;
    }

    /**
     * The paths links were left at for the second pass, in order.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        return array_keys($this->pending);
    }

    /**
     * A link to a file in the library, by its ID with the address's place;
     * null, said with the file's name, when Teme doesn't have it.
     */
    private function file(string $href, string $key): ?string
    {
        $mediaId = ImageTrace::lookup($key, $this->media);
        $hash = strpos($href, '#');
        $link = $mediaId === null ? null : $this->byId('media', $mediaId, $hash === false ? '' : substr($href, $hash));

        if ($link === null) {
            $this->report->count('file.missing', '', basename(rawurldecode((string) parse_url($href, PHP_URL_PATH))));
        } else {
            $this->noteOtherHost($href, 'links.other-host');
        }

        return $link;
    }

    /**
     * A page on the old site: a link by ID to what it became, or its path,
     * for the second pass unless it is a later page of a list.
     *
     * @param  array{path: string, query: string, place: string}  $page
     */
    private function page(array $page, string $href): ?string
    {
        parse_str($page['query'], $vars);
        $later = self::later($page['path'], $vars);
        $target = $later ? null : $this->target($page['path'], $page['query']);
        $link = $target === null ? null : $this->byId($target['kind'], $target['id'], $page['place']);

        if ($link !== null) {
            $this->noteOtherHost($href, 'links.other-host');

            return $link;
        }

        $path = self::encoded($page['path'].($page['query'] === '' ? '' : '?'.$page['query']).$page['place']);

        if ($path === null || preg_match(DocumentSchema::SITE_PATH, $path) !== 1) {
            return null;
        }

        if (! $later) {
            $this->pending[$path] = true;
            $this->noteOtherHost($href, 'links.other-host');
        }

        return $path;
    }

    /**
     * Counts a conversion from another old address of this site, by host.
     */
    private function noteOtherHost(string $href, string $code): void
    {
        $host = $this->site->hostOf($href);

        if ($host !== null && $this->site->hostKind($host) === 'other') {
            $this->report->count($code, $host);
        }
    }

    /**
     * What the import made of the page at this path and query: at the home
     * with a query, what WordPress's query variables name and nothing else
     * (never the front page); otherwise the page's key.
     *
     * @return array{kind: string, id: string}|null
     */
    private function target(string $path, string $query): ?array
    {
        parse_str($query, $vars);
        $bare = rtrim($path, '/');
        $bare = str_ends_with($bare, '/index.php') ? substr($bare, 0, -strlen('/index.php')) : $bare;

        if ($vars !== [] && rtrim($bare, '/') === $this->site->homePath()) {
            return $this->byQuery($vars);
        }

        return $this->targets->page(ConversionSite::pageKey($path));
    }

    /**
     * What WordPress's query variables name: a file, an article, a term
     * or a person; null for anything else.
     *
     * @param  array<mixed>  $vars
     *
     * @return array{kind: string, id: string}|null
     */
    private function byQuery(array $vars): ?array
    {
        $number = fn (string $name): ?int => is_string($vars[$name] ?? null) && preg_match('/^[1-9]\d{0,17}$/', $vars[$name]) === 1 ? (int) $vars[$name] : null;
        $slug = fn (string $name): ?string => is_string($vars[$name] ?? null) && preg_match('#^[^,+\s]+$#', $vars[$name]) === 1 ? rawurldecode(basename($vars[$name])) : null;

        $attachment = $number('attachment_id');

        if ($attachment !== null) {
            return $this->mediaOf($attachment);
        }

        foreach (['p', 'page_id'] as $name) {
            $id = $number($name);

            if ($id !== null) {
                $content = $this->targets->post($id);

                return $content !== null ? ['kind' => 'content', 'id' => $content] : ($name === 'p' ? $this->mediaOf($id) : null);
            }
        }

        foreach (self::TERM_IDS as [$name, $taxonomy]) {
            $id = $number($name);
            $term = $id === null ? null : $this->targets->term($taxonomy, $id);

            if ($term !== null) {
                return ['kind' => 'term', 'id' => $term];
            }
        }

        foreach (self::TERM_SLUGS as [$name, $taxonomy]) {
            $named = $slug($name);
            $term = $named === null ? null : $this->targets->termNamed($taxonomy, $named);

            if ($term !== null) {
                return ['kind' => 'term', 'id' => $term];
            }
        }

        $id = $number('author');
        $named = $slug('author_name');
        $user = $id !== null ? $this->targets->user($id) : ($named === null ? null : $this->targets->userNamed($named));

        return $user === null ? null : ['kind' => 'user', 'id' => $user];
    }

    /**
     * @return array{kind: string, id: string}|null
     */
    private function mediaOf(int $attachment): ?array
    {
        $mediaId = $this->media->attachment($attachment);

        return $mediaId === null ? null : ['kind' => 'media', 'id' => $mediaId];
    }

    /**
     * A link by ID with its place, the ID in its table's case (I-418); null
     * when the result isn't one a link may have.
     */
    private function byId(string $kind, string $id, string $place): ?string
    {
        $id = $kind === 'media' ? strtoupper($id) : strtolower($id);
        $link = DocumentSchema::linkTo($kind, $id, self::place($place));

        return DocumentSchema::linkTarget($link) === null ? null : $link;
    }

    /**
     * Whether the address is a later page of a list, which has no ID.
     *
     * @param  array<mixed>  $vars
     */
    private static function later(string $path, array $vars): bool
    {
        $paged = filter_var($vars['paged'] ?? null, FILTER_VALIDATE_INT);

        return (is_int($paged) && $paged > 1) || preg_match('#/page/\d+/?$#', $path) === 1;
    }

    /**
     * A place with the characters a fragment can't hold encoded; '' for none
     * or `#` alone.
     */
    private static function place(string $place): string
    {
        $encoded = preg_replace_callback(DocumentSchema::NOT_IN_FRAGMENT, fn (array $character): string => rawurlencode($character[0]), substr($place, 1));

        return $encoded === null || $encoded === '' ? '' : '#'.$encoded;
    }

    /**
     * A site path with the characters it can't hold encoded, or null when
     * the text isn't UTF-8.
     */
    private static function encoded(string $path): ?string
    {
        return preg_replace_callback(DocumentSchema::NOT_IN_SITE_PATH, fn (array $character): string => rawurlencode($character[0]), $path);
    }
}
