<?php

/**
 * Brings one WordPress menu in as a Teme menu (I-343): made with its name
 * and whole tree, or, on a later run, its name and tree replaced, only
 * while it is as the import last left it, so a later run never undoes a
 * person's edit. The tree meets SaveNavigationItems' own checks and is
 * written by the same code.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Concerns\ReplacesNavigationItems;
use App\Domain\Menus\Operations\Input\ImportNavigationInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class ImportNavigation extends BaseOperation
{
    use AuthorizesImports;
    use ManagesNavigation;
    use ReplacesNavigationItems;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportNavigationInput $input */
        $this->validateName($input->name);
        $this->validateLanguage($input->language);
        SaveNavigationItems::check($input->tree);
    }

    /**
     * @return array{navigationId: string, created: bool, changedHere: bool, fingerprint: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportNavigationInput $input */
        $name = trim($input->name);
        $navigation = null;

        if ($input->navigationId !== null) {
            $navigation = Navigation::query()->lockForUpdate()->find($input->navigationId);

            if ($navigation === null) {
                throw ValidationException::withMessages(['navigationId' => 'The menu isn’t there to update.']);
            }

            $now = self::fingerprint($navigation);

            // A person's edit since the import last wrote it is never undone.
            if ($input->expectedFingerprint !== null && ! hash_equals($input->expectedFingerprint, $now)) {
                return ['navigationId' => $navigation->id, 'created' => false, 'changedHere' => true, 'fingerprint' => $now];
            }

            if ($navigation->name !== $name) {
                $navigation->name = $name;
                $navigation->save();
            }
        }

        $created = $navigation === null;

        if ($navigation === null) {
            // The two lists import side by side: one new handle at a time.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['navigations.handle']);
            $navigation = Navigation::query()->create(['name' => $name, 'handle' => CreateNavigation::handleFor($name), 'language' => self::storedLanguage($input->language)]);
        }

        $this->replace($navigation, $input->tree);

        // Every page that draws it (I-263); an import run holds the purge until it ends.
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MENU, (string) $navigation->handle, [PageTags::menu((string) $navigation->handle)]));

        return ['navigationId' => $navigation->id, 'created' => $created, 'changedHere' => false, 'fingerprint' => self::fingerprint($navigation)];
    }

    /**
     * The menu as people see it, as one hash: its name, and each item's
     * kind, target, address, label and new-tab choice in its place, under
     * its parent. Item IDs and times are left out, so a save that changed
     * nothing keeps it.
     */
    private static function fingerprint(Navigation $navigation): string
    {
        $items = DB::table('navigation_items')
            ->where('navigation_id', $navigation->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'kind', 'target_id', 'url', 'label', 'opens_new_tab', 'options'])
            ->groupBy(fn (object $item): string => (string) ($item->parent_id ?? ''));

        return hash('sha256', json_encode([$navigation->name, self::branch($items, '')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * One parent's items as plain values, each with its own under it.
     *
     * @param  Collection<array-key, Collection<int, stdClass>>  $items
     *
     * @return list<array<int, mixed>>
     */
    private static function branch(Collection $items, string $parent): array
    {
        $branch = [];

        foreach ($items->get($parent) ?? [] as $item) {
            $values = [(string) $item->kind, $item->target_id, $item->url, $item->label, (bool) $item->opens_new_tab, self::branch($items, (string) $item->id)];

            // Only a switcher has options; every other item hashes as it always has.
            if ($item->options !== null) {
                $values[] = json_decode((string) $item->options, true, flags: JSON_THROW_ON_ERROR);
            }

            $branch[] = $values;
        }

        return $branch;
    }
}
