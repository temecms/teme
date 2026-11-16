<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\ImportNavigation;
use App\Domain\Menus\Operations\Input\ImportNavigationInput;

/**
 * The menus phase (I-343): each classic menu and each published navigation
 * post of the old site becomes a Teme menu, its items leading by ID to what
 * the import brought and to the old site's other addresses as paths here;
 * a later run follows WordPress's changes while a menu is as the import
 * left it, and never brings back one deleted here.
 */
final readonly class MenusPhase implements ListPhase
{
    /** A navigation post's ID in the map, apart from classic menus' term IDs. */
    public const string BLOCK_MENU = 'navigation:';

    /** The companion's route, and the two lists its answer holds. */
    private const string ROUTE = 'menus';

    private const string CLASSIC = 'menus';

    private const string BLOCKS = 'navigation';

    /** WordPress's status for a published post. */
    private const string PUBLISHED = 'publish';

    public function __construct(private ImportMap $map, private MenuTargets $targets, private ImportSources $sources) {}

    public function key(): string
    {
        return 'menus';
    }

    public function order(): int
    {
        return 800;
    }

    public function label(): string
    {
        return 'Menus';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        return [ImportList::whole(self::ROUTE, self::CLASSIC), ImportList::whole(self::ROUTE, self::BLOCKS)];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        $id = (int) ($item['id'] ?? 0);

        return SourceRef::of('menu', $list->itemsAt === self::BLOCKS ? self::BLOCK_MENU.$id : (string) $id);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $block = str_starts_with($ref->id, self::BLOCK_MENU);
        $entry = $this->map->entry($context->sourceId, $ref);
        $navigationId = $entry?->teme_id;

        if ($block && ($item['status'] ?? '') !== self::PUBLISHED) {
            return ImportResult::skipped(new ImportMessage('menu.draft', 'It’s a draft on WordPress, which no page there shows, so it wasn’t imported.'));
        }

        if ($navigationId !== null && ! Navigation::query()->whereKey($navigationId)->exists()) {
            return ImportResult::skipped(new ImportMessage('menu.deleted-here', 'It was deleted on Teme after an earlier import, so it was left deleted.'));
        }

        $read = $block ? (new WordPressMenus)->blocks(self::listed($item['blocks'] ?? null)) : (new WordPressMenus)->classic(self::listed($item['items'] ?? null));
        $found = $this->targets->resolve($read['nodes'], $context->sourceId, $this->site($context), $entry?->status === ImportStatus::NeedsUpdate);

        if ($found['waiting'] !== []) {
            throw new ImportFailed(self::waiting($found['waiting']));
        }

        $shaped = MenuShape::tree($found['nodes']);
        $fingerprint = $entry?->facts['fingerprint'] ?? null;

        $saved = app(ImportNavigation::class)->execute(new ImportNavigationInput(
            navigationId: $navigationId,
            name: self::name($item[$block ? 'title' : 'name'] ?? '', $block),
            tree: $shaped['tree'],
            expectedFingerprint: is_string($fingerprint) ? $fingerprint : null,
            actorId: $context->actorId(),
        ));

        if ($saved['changedHere']) {
            return ImportResult::skipped(new ImportMessage('menu.changed-here', 'It was changed on Teme after an earlier import, so WordPress’s later changes to it weren’t brought across.'));
        }

        $result = $saved['created'] ? ImportResult::created('navigation', $saved['navigationId']) : ImportResult::updated('navigation', $saved['navigationId']);

        foreach ([...$read['messages'], ...$found['messages'], ...$shaped['messages']] as $message) {
            $result = $result->withWarning($message);
        }

        if ($block) {
            $result = $result->withWarning(new ImportMessage('menu.not-placed', 'A block theme showed it from its templates, which Teme doesn’t read, so it isn’t shown anywhere yet: choose its place under Content › Navigation.'));
        }

        return $result->withFacts(['fingerprint' => $saved['fingerprint']]);
    }

    /**
     * @return list<mixed>
     */
    private static function listed(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * A menu's name as WordPress showed it, in the 120 characters a menu's
     * name holds; WordPress's own word when it has none.
     */
    private static function name(mixed $name, bool $block): string
    {
        $text = rtrim(mb_substr(WordPressMenus::label($name)['text'], 0, 120));

        return $text !== '' ? $text : ($block ? 'Navigation' : 'Menu');
    }

    /**
     * Why the menu waits, naming the first ten posts it waits for.
     *
     * @param  list<int>  $ids
     */
    private static function waiting(array $ids): ImportMessage
    {
        $first = array_map(strval(...), array_slice($ids, 0, 10));
        $more = count($ids) - count($first);
        $listed = WordPressMenus::joined($more > 0 ? [...$first, "{$more} more"] : $first);

        return ImportMessage::error('menu.waiting', count($ids) === 1
            ? "It leads to a post or page that couldn’t be imported (WordPress ID {$listed}), so it waits for it: a full import tries both again."
            : "It leads to posts or pages that couldn’t be imported (WordPress IDs {$listed}), so it waits for them: a full import tries them all again.", ['ids' => array_slice($ids, 0, 10)]);
    }

    /**
     * The old site's own addresses, so its links are told from others'.
     */
    private function site(ImportContext $context): ConversionSite
    {
        $source = $this->sources->find($context->sourceId);
        $otherHosts = $source === null
            ? []
            : array_values(array_filter((array) ($source->other_hosts ?? []), is_string(...)));

        return new ConversionSite((string) $source?->address, otherHosts: $otherHosts);
    }
}
