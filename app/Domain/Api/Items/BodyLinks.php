<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\References;
use App\Domain\Languages\SiteLanguages;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Where one API answer's body links lead (rule 9, D7), read for the whole
 * document at once: every item, term and person it links to by ID in one
 * statement, by the site's rule (a current address, as PathLinkResolver
 * reads it), so a body of many links costs what a body of one costs. Files
 * are the site's resolver's, read with the body's media. Made for one answer
 * and shared by its HTML and its JSON; the public site's resolver is untouched.
 */
final class BodyLinks implements LinkResolver
{
    /**
     * Each target the document names, by "kind:id": its site-relative
     * address now, or null when it has none.
     *
     * @var array<string, string|null>
     */
    private array $known = [];

    public function __construct(private readonly LinkResolver $site) {}

    /**
     * The resolver for this document, every item, term and person it links
     * to read in one statement.
     *
     * @param  array<mixed>  $document
     */
    public static function for(array $document, LinkResolver $site): self
    {
        $links = new self($site);
        $links->read(References::of($document));

        return $links;
    }

    public function url(string $contentId): ?string
    {
        return $this->target('content', $contentId);
    }

    public function path(string $path): string
    {
        return $path;
    }

    public function target(string $kind, string $id): ?string
    {
        if ($kind === 'media') {
            return $this->site->target($kind, $id);
        }

        if (! in_array($kind, ['content', 'term', 'user'], true)) {
            return null;
        }

        // On every call, found before or not, as PathLinkResolver does: the link changes with its target (I-263).
        app(RenderRecord::class)->tag(match ($kind) {
            'content' => PageTags::content($id),
            'term' => PageTags::term($id),
            default => PageTags::person($id),
        });

        if (! array_key_exists("{$kind}:{$id}", $this->known)) {
            // A target the document's references didn't name, such as one written past the editor: read alone.
            $this->read([CardReference::linkTo($kind, $id)]);
        }

        return $this->known["{$kind}:{$id}"] ?? null;
    }

    /**
     * Reads the current address of each item, term and person named, in
     * one statement, and remembers each, one with none as null.
     *
     * @param  list<CardReference>  $references
     */
    private function read(array $references): void
    {
        $ids = ['content' => [], 'term' => [], 'user' => []];

        foreach ($references as $reference) {
            $kind = $reference->kind;
            $id = $reference->id;

            // Only an ID of the form its table stores reaches the statement.
            if (array_key_exists($kind, $ids) && ! array_key_exists("{$kind}:{$id}", $this->known)
                && preg_match('/^'.DocumentSchema::LINK_TARGETS[$kind].'$/', $id) === 1) {
                $ids[$kind][] = $id;
                $this->known["{$kind}:{$id}"] = null;
            }
        }

        if ($ids === ['content' => [], 'term' => [], 'user' => []]) {
            return;
        }

        $rows = DB::table('paths')
            // An item's address needs its row, and its type for the pattern's trailing slash, as PathLinkResolver joins it.
            ->leftJoin('contents', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))
                ->where('paths.target_type', 'content')
                ->whereIn('contents.id', $ids['content']))
            ->where('paths.is_current', true)
            ->where(function ($query) use ($ids): void {
                foreach ($ids as $kind => $named) {
                    if ($named !== []) {
                        $query->orWhere(fn ($by) => $by->where('paths.target_type', $kind)->whereIn('paths.target_id', $named));
                    }
                }
            })
            ->get(['paths.target_type', 'paths.target_id', 'paths.path', 'contents.type']);

        foreach ($rows as $row) {
            $kind = (string) $row->target_type;

            if ($kind === 'content' && $row->type === null) {
                continue;
            }

            // A target in a hidden language is given as the page draws it: its text, no address (D7).
            if (app(SiteLanguages::class)->isHiddenPath((string) $row->path)) {
                continue;
            }

            $this->known[$kind.':'.$row->target_id] = $kind === 'content'
                ? Addresses::toUrl((string) $row->path, (string) $row->type)
                : Addresses::toUrl((string) $row->path);
        }
    }
}
