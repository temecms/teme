<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Terms\ImportedTerms;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Domain\Taxonomy\Models\Term;
use Illuminate\Support\Facades\DB;

/**
 * What each item of a WordPress menu is on Teme (I-343): a post, page or
 * term the import brought, by ID; a page list as the pages it showed; an
 * address as a menu can hold it, the old site's own pages as paths here,
 * never its host; and, for what the import didn't bring, its old address.
 * Reads the import map; writes nothing.
 */
final class MenuTargets
{
    /** No address: a heading over items, or nothing. */
    public const string NONE = 'none';

    /** The old site's own page, as a path here. */
    public const string OWN = 'own';

    /** Another site's address, an email or a phone link, as it is. */
    public const string OTHER = 'other';

    /** Nothing a menu can hold. */
    public const string REFUSED = 'refused';

    /** The map's type for a WordPress post or page, and the Teme side's for an item. */
    private const string POSTS = 'post';

    private const string CONTENT = 'content';

    /** A page list lists Teme's pages, published. */
    private const string PAGE_TYPE = 'page';

    private const string PUBLISHED = 'published';

    /** @var array<int, array{content: string|null, waiting: bool}> what the map says of each post the menu leads to */
    private array $posts = [];

    /** @var list<ImportMessage> */
    private array $messages = [];

    /** @var list<int> */
    private array $waiting = [];

    private string $sourceId = '';

    private bool $retry = false;

    private ConversionSite $site;

    public function __construct(private readonly ImportedTerms $terms) {}

    /**
     * Returns each node found, in place, a page list's pages where it
     * stood; what the report says of them; and the WordPress posts the menu
     * waits for: those that failed, unless this is the menu's own retry,
     * when they lead to their old addresses instead.
     *
     * @param  list<MenuNode>  $nodes
     *
     * @return array{nodes: list<MenuNode>, messages: list<ImportMessage>, waiting: list<int>}
     */
    public function resolve(array $nodes, string $sourceId, ConversionSite $site, bool $retry): array
    {
        $this->sourceId = $sourceId;
        $this->site = $site;
        $this->retry = $retry;
        $this->messages = [];
        $this->waiting = [];
        $this->posts = $this->postsOf(self::itemIds($nodes));
        $found = $this->each($nodes);

        return ['nodes' => $found, 'messages' => $this->messages, 'waiting' => array_values(array_unique($this->waiting))];
    }

    /**
     * Returns this source's pages published here and not in the trash, as
     * WordPress's page list showed them: those under WordPress's page
     * $parent (0 for the top), each with its own under it, by WordPress's
     * menu order, then title, then ID. A page whose parent isn't among
     * them isn't listed, as on WordPress.
     *
     * @return list<MenuNode>
     */
    public function pages(string $sourceId, int $parent): array
    {
        $under = [];

        foreach (DB::table('import_map')
            ->join('contents', 'contents.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $sourceId)
            ->where('import_map.object_type', self::POSTS)
            ->where('import_map.teme_type', self::CONTENT)
            ->where('contents.type', self::PAGE_TYPE)
            ->where('contents.status', self::PUBLISHED)
            ->whereNull('contents.deleted_at')
            ->orderByRaw("case when jsonb_typeof(import_map.facts->'menu_order') = 'number' then (import_map.facts->>'menu_order')::numeric else 0 end")
            ->orderByRaw('lower(contents.title)')
            ->orderBy('contents.id')
            ->get(['import_map.object_id', 'import_map.teme_id', 'import_map.facts', 'contents.title']) as $row) {
            $facts = json_decode((string) $row->facts, true);
            $up = is_array($facts) && is_numeric($facts['parent'] ?? null) ? (int) $facts['parent'] : 0;
            $under[$up][] = ['id' => (int) $row->object_id, 'content' => (string) $row->teme_id, 'title' => (string) $row->title];
        }

        return self::pagesUnder($parent, $under, []);
    }

    /**
     * @param  list<MenuNode>  $nodes
     *
     * @return list<MenuNode>
     */
    private function each(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            array_push($found, ...$this->one($node->with($this->each($node->children))));
        }

        return $found;
    }

    /**
     * One node found; a page list gives its pages.
     *
     * @return list<MenuNode>
     */
    private function one(MenuNode $node): array
    {
        return match ($node->leadsTo) {
            MenuNode::ITEM => [$this->item($node)],
            MenuNode::TERM => [$this->term($node)],
            MenuNode::HOME => [$node->found(['kind' => 'home', 'target_id' => null, 'url' => null])],
            MenuNode::PAGES => $this->pageList($node),
            default => [$this->leadsToAddress($node)],
        };
    }

    private function item(MenuNode $node): MenuNode
    {
        $post = $this->posts[$node->objectId] ?? null;

        if ($post !== null && $post['content'] !== null) {
            return $node->found(['kind' => self::CONTENT, 'target_id' => $post['content'], 'url' => null]);
        }

        if ($post !== null && $post['waiting'] && ! $this->retry) {
            $this->waiting[] = $node->objectId;

            return $node->found(null);
        }

        return $this->notImported($node);
    }

    private function term(MenuNode $node): MenuNode
    {
        $termId = $this->terms->byWordPressTerm($this->sourceId, $node->taxonomy, $node->objectId);

        return $termId !== null && Term::query()->whereKey($termId)->exists()
            ? $node->found(['kind' => 'term', 'target_id' => $termId, 'url' => null])
            : $this->notImported($node);
    }

    /**
     * An item whose target the import doesn't bring: its old address, a
     * path here or another site's, said; or left out when WordPress gave
     * none a menu can hold.
     */
    private function notImported(MenuNode $node): MenuNode
    {
        [$how, $url] = self::address($node->address, $this->site);

        if ($how === self::OWN || $how === self::OTHER) {
            $shown = mb_substr($url, 0, 80);
            $this->messages[] = new ImportMessage('menu.not-imported', ucfirst($node->name)." led to something this import doesn’t bring, so it leads to its old address, {$shown}.", ['item' => $node->objectId, 'address' => $shown]);

            return $node->found(['kind' => 'url', 'target_id' => null, 'url' => $url]);
        }

        $this->messages[] = new ImportMessage('menu.target-gone', ucfirst($node->name).' led to something this import doesn’t bring, and WordPress gave no address a menu can hold for it, so it was left out.', ['item' => $node->objectId]);

        return $node->found(null);
    }

    /**
     * An item that leads to an address, as a menu can hold it: a heading
     * when there's none, left out when a menu can't hold it, both said
     * where they're left out.
     */
    private function leadsToAddress(MenuNode $node): MenuNode
    {
        [$how, $url] = self::address($node->address, $this->site);

        if ($how === self::NONE) {
            return $node->found(null, heading: true);
        }

        if ($how === self::REFUSED) {
            // An address holding a user name, as an email does, is never repeated.
            $shown = preg_match(WordPressMenus::EMAIL, $node->address) === 1 ? 'an address with a user name in it' : mb_substr(trim($node->address), 0, 80);
            $this->messages[] = new ImportMessage('menu.address-refused', ucfirst($node->name)." led to {$shown}, which a Teme menu can’t hold, so it was left out.", ['address' => $shown]);

            return $node->found(null);
        }

        return $node->found(['kind' => 'url', 'target_id' => null, 'url' => $url]);
    }

    /**
     * A page list's pages, said; nothing, said, when it shows none.
     *
     * @return list<MenuNode>
     */
    private function pageList(MenuNode $node): array
    {
        $pages = $this->pages($this->sourceId, $node->objectId);
        $count = 0;

        foreach ($pages as $page) {
            $count += $page->count();
        }

        if ($count === 0) {
            $this->messages[] = new ImportMessage('menu.page-list-empty', 'WordPress’s page list had no page to show, so it was left out.');

            return [];
        }

        $this->messages[] = new ImportMessage('menu.page-list', $count === 1
            ? 'WordPress’s page list showed every published page; here it’s a fixed list of the 1 page it showed, and pages added later aren’t added to it.'
            : "WordPress’s page list showed every published page; here it’s a fixed list of the {$count} pages it showed, and pages added later aren’t added to it.", ['count' => $count]);

        return $pages;
    }

    /**
     * What the map says of these WordPress posts and pages: the Teme item
     * each became while it's still here, in the trash or not, and whether
     * it failed and waits for a run to try it again.
     *
     * @param  list<int>  $ids
     *
     * @return array<int, array{content: string|null, waiting: bool}>
     */
    private function postsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = ImportMapEntry::query()
            ->where('source_id', $this->sourceId)
            ->where('object_type', self::POSTS)
            ->whereIn('object_id', array_map(strval(...), $ids))
            ->get(['object_id', 'teme_type', 'teme_id', 'status']);
        $here = DB::table('contents')
            ->whereIn('id', $rows->where('teme_type', self::CONTENT)->pluck('teme_id')->filter()->values()->all())
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $posts = [];

        foreach ($rows as $row) {
            $posts[(int) $row->object_id] = [
                'content' => $row->teme_type === self::CONTENT && in_array((string) $row->teme_id, $here, true) ? (string) $row->teme_id : null,
                'waiting' => $row->teme_id === null && in_array($row->status, [ImportStatus::Failed, ImportStatus::NeedsUpdate], true),
            ];
        }

        return $posts;
    }

    /**
     * Every post or page these nodes lead to, at any depth.
     *
     * @param  list<MenuNode>  $nodes
     *
     * @return list<int>
     */
    private static function itemIds(array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            if ($node->leadsTo === MenuNode::ITEM && $node->objectId > 0) {
                $ids[] = $node->objectId;
            }

            array_push($ids, ...self::itemIds($node->children));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, list<array{id: int, content: string, title: string}>>  $under
     * @param  array<int, true>  $above  the pages above, so a page that is its own ancestor stops
     *
     * @return list<MenuNode>
     */
    private static function pagesUnder(int $parent, array $under, array $above): array
    {
        $nodes = [];

        foreach ($under[$parent] ?? [] as $page) {
            if (isset($above[$page['id']])) {
                continue;
            }

            $title = WordPressMenus::label($page['title'])['text'];
            $name = WordPressMenus::named($title, "a page with no title (WordPress ID {$page['id']})", "a page titled with an email address (WordPress ID {$page['id']})");
            $node = new MenuNode($name, '', false, MenuNode::ITEM, $page['id']);
            $nodes[] = $node
                ->with(self::pagesUnder($page['id'], $under, $above + [$page['id'] => true]))
                ->found(['kind' => self::CONTENT, 'target_id' => $page['content'], 'url' => null]);
        }

        return $nodes;
    }

    /**
     * What an address from WordPress becomes in a menu, and how: none (''
     * or only a place on the page, # or #contact), a path here for the old
     * site's own page (http or https on its home's host, with or without
     * www., //host read with https:, or a path; its query and place kept),
     * another site's address, an email or a phone link as it is, or nothing
     * a menu can hold, any other address on the old site's host or Jetpack's
     * copy of one among them. Whitespace is percent-encoded first, as a
     * browser sends it.
     *
     * @return array{0: string, 1: string}
     */
    public static function address(string $address, ConversionSite $site): array
    {
        $address = trim($address);

        if ($address === '' || str_starts_with($address, '#')) {
            return [self::NONE, ''];
        }

        $address = (string) preg_replace_callback('/\s/', fn (array $space): string => rawurlencode($space[0]), $address);
        $href = str_starts_with($address, '//') ? 'https:'.$address : $address;
        $page = $site->pageOf($href);

        if ($page !== null) {
            $path = $page['path'].($page['query'] === '' ? '' : '?'.$page['query']).$page['place'];

            return SaveNavigationItems::allowsAddress($path) ? [self::OWN, $path] : [self::REFUSED, ''];
        }

        // Never the old host otherwise: a user name in the address, or Jetpack's copy of an old file.
        $home = $site->hostOf($site->home);

        if ($site->isOwn($href) || ($home !== null && $site->hostOf($href) === $home)) {
            return [self::REFUSED, ''];
        }

        return ! str_starts_with($href, '/') && SaveNavigationItems::allowsAddress($href) ? [self::OTHER, $href] : [self::REFUSED, ''];
    }
}
