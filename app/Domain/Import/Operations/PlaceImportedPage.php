<?php

/**
 * Puts one imported page back under its WordPress parent, at its rank among
 * its WordPress siblings (I-3363), unless a person moved it in Teme or
 * something stands in the way, which its map row's note then says. Its
 * object, the tree and the page are locked before anything is checked; the
 * pages a person put under it move with it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\TreeRules;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\PlaceImportedPageInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\PlacedPage;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class PlaceImportedPage extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    /** The map's object type for a WordPress post or page (I-332). */
    private const string OBJECT = 'post';

    public function __construct(
        private readonly ImportMap $map,
        private readonly ContentTree $tree,
        private readonly SubtreeAddresses $subtree,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PlaceImportedPageInput $input */
        if (! Str::isUlid($input->sourceId) || preg_match('/^[1-9]\d{0,17}$/', $input->objectId) !== 1 || $input->rank < 0 || $input->rank > 99999) {
            throw new InvalidArgumentException('Placing an imported page needs the site, a page’s WordPress ID and its rank.');
        }
    }

    protected function perform(OperationInput $input): PlacedPage
    {
        /** @var PlaceImportedPageInput $input */
        $ref = SourceRef::of(self::OBJECT, $input->objectId);
        $temeId = $this->map->temeId($input->sourceId, $ref);
        $type = $temeId === null ? null : DB::table('contents')->where('id', $temeId)->value('type');

        if ($temeId === null) {
            return new PlacedPage(PlacedPage::LEFT);
        }

        // The object, then the tree, then the page, before anything is checked: the import's own
        // order (ImportObject locks the object, then its content Operation the tree), so the two
        // never wait on each other.
        $this->lockObject($input->sourceId, $ref);

        if (! $this->subtree->lock(is_string($type) ? $type : null)) {
            return new PlacedPage(PlacedPage::LEFT);
        }

        $entry = $this->map->entry($input->sourceId, $ref);
        $page = Content::withTrashed()->lockForUpdate()->find($temeId);

        if ($entry === null || $page === null) {
            return new PlacedPage(PlacedPage::LEFT);
        }

        $facts = $entry->facts;
        // Read back in the order written: JSONB keeps its keys in its own order.
        $placed = is_array($facts['placed'] ?? null) ? ['parent_id' => $facts['placed']['parent_id'] ?? null, 'position' => (int) ($facts['placed']['position'] ?? 0)] : null;
        $title = '“'.$page->title.'”';
        $now = ['parent_id' => $page->parent_id, 'position' => (int) $page->position];

        if ($now !== ($placed ?? ['parent_id' => null, 'position' => 0])) {
            return $this->leave($entry, 'page.moved-here', __(':title was moved in Teme, so it stays where you put it.', ['title' => $title]));
        }

        [$parent, $code, $note] = $this->parent($input->sourceId, (int) ($facts['parent'] ?? 0), $page, $title);

        if ($code !== null) {
            return $this->leave($entry, $code, (string) $note);
        }

        $target = ['parent_id' => $parent?->id, 'position' => $input->rank];
        $slug = Str::afterLast(trim((string) ($facts['path'] ?? ''), '/'), '/');

        if ($now === $target && $placed === $target) {
            return new PlacedPage(PlacedPage::UNCHANGED);
        }

        // Its own address, with its WordPress parents' slugs, must be free.
        $probe = (clone $page)->forceFill(['parent_id' => $parent?->id]);
        $wanted = Addresses::forContent($probe);
        if ($wanted !== null && Paths::conflict($wanted, 'content', (string) $page->id) !== null) {
            $flat = Paths::current('content', (string) $page->id);

            return $this->leave($entry, 'page.address-taken', __(':title stays at :flat: WordPress had it at :nested, which :holder uses here.', [
                'title' => $title,
                'flat' => $flat === null ? '' : Addresses::toUrl($flat, (string) $page->type),
                'nested' => Addresses::toUrl($wanted, (string) $page->type),
                'holder' => self::holder($wanted),
            ]));
        }

        $chainBefore = $this->subtree->hasChildren($page) ? $this->subtree->childChain($page) : null;
        $before = Footprints::content($page);
        DB::table('contents')->where('id', $page->id)->update($target);
        $page->refresh();
        // Later (issue 364): announce the address change here.
        app(PathProjection::class)->syncContent($page);
        $kept = [];

        if ($chainBefore !== null) {
            $outcome = $this->subtree->follow($page, $chainBefore, $input->actorId());
            $kept = $outcome->kept;

            foreach ($outcome->purges as $purge) {
                $this->purgeAfterCommit($purge);
            }
        }

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $page->id, [...$before, ...Footprints::content($page)]));

        // The import gave it another slug than WordPress's (I-1710): it keeps it.
        [$code, $note] = $slug !== '' && $slug !== $page->slug
            ? ['page.slug-suffixed', __(':title is at :address: another page had the name :slug when it was imported.', ['title' => $title, 'address' => Addresses::toUrl((string) Paths::current('content', (string) $page->id), (string) $page->type), 'slug' => $slug])]
            : [null, null];

        $this->note($entry, ['placed' => $target], $note);

        return new PlacedPage(PlacedPage::PLACED, $code, $note, $kept);
    }

    /**
     * The page's WordPress parent here, or why it can't go under it: its
     * item (null for the top level), the note's code and its words.
     *
     * @return array{0: Content|null, 1: string|null, 2: string|null}
     */
    private function parent(string $sourceId, int $wordpressParent, Content $page, string $title): array
    {
        if ($wordpressParent === 0) {
            return [null, null, null];
        }

        $parentId = $this->map->temeId($sourceId, SourceRef::of(self::OBJECT, $wordpressParent));
        $parent = $parentId === null ? null : Content::withTrashed()->find($parentId);

        if ($parent === null) {
            return [null, 'page.parent-missing', __(":title sits under a page that wasn't imported, so it's at the top level here.", ['title' => $title])];
        }

        $words = ['title' => $title, 'parent' => '“'.$parent->title.'”'];

        if ($parent->trashed()) {
            return [null, 'page.parent-trashed', __(":title's parent :parent is in the trash here, so it stays where it is.", $words)];
        }

        if ($parent->type !== $page->type) {
            return [null, 'page.parent-type', __(":title's parent :parent is a different kind of item here, so it stays where it is.", $words)];
        }

        if ($this->tree->levels((string) $parent->id) + $this->tree->height((string) $page->id) > ContentTree::MOST_LEVELS) {
            return [null, 'page.too-deep', __(":title is more than eight levels down, so it's at the top level here, with its own pages under it.", ['title' => $title])];
        }

        $refusal = app(TreeRules::class)->refusal($page, (string) $parent->id);

        if ($refusal !== null) {
            return [null, 'page.refused', __(":title couldn't go back under :parent: :reason", [...$words, 'reason' => $refusal])];
        }

        return [$parent, null, null];
    }

    /**
     * What holds the address: another target, or a type's archive page.
     */
    private static function holder(string $address): string
    {
        $row = DB::table('paths')->where('path', $address)->where('is_current', true)->first(['target_type', 'target_id']);

        if ($row !== null) {
            return Paths::describe((string) $row->target_type, (string) $row->target_id);
        }

        $archive = TypeArchives::at($address);

        return $archive === null ? '' : Paths::describe(TypeArchives::TARGET, $archive);
    }

    /**
     * Leaves the page where it is, its map row saying why.
     */
    private function leave(ImportMapEntry $entry, string $code, string $note): PlacedPage
    {
        if (($entry->facts['parent_note'] ?? null) !== $note) {
            $this->note($entry, [], $note);
        }

        return new PlacedPage(PlacedPage::LEFT, $code, $note);
    }

    /**
     * Writes these facts to the page's map row, with its note, or without one.
     *
     * @param  array<string, mixed>  $facts
     */
    private function note(ImportMapEntry $entry, array $facts, ?string $note): void
    {
        $next = [...$entry->facts, ...$facts];
        unset($next['parent_note']);

        if ($note !== null) {
            $next['parent_note'] = $note;
        }

        $entry->forceFill(['facts' => $next])->save();
    }
}
