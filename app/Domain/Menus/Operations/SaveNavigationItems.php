<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Concerns\ReplacesNavigationItems;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Replaces a menu's items with a whole new tree, in one transaction. Each
 * item points at a content item or a term by ID (rule 9), at the home page,
 * or at an outside address or a path on this site, or is a heading, which
 * leads nowhere and opens the items under it (I-2628). An address typed in
 * full as this site's own is stored as what it names, never as the site's
 * URL. A menu is at most three levels deep and 200 items long, and every
 * problem names the item it is on, as "Item 3 › 2: …". Its checks are
 * ImportNavigation's too, and its writing their shared trait's.
 */
final class SaveNavigationItems extends BaseOperation
{
    use ManagesNavigation;
    use ReplacesNavigationItems;

    /** Deeper menus aren't usable, and the limit keeps drawing cheap. */
    public const int MAX_DEPTH = 3;

    public const int MAX_ITEMS = 200;

    /** @var list<string> */
    public const array KINDS = ['content', 'term', 'home', 'url', 'heading'];

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveNavigationItemsInput $input */
        $this->authorizeNavigation($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveNavigationItemsInput $input */
        if (! Navigation::query()->whereKey($input->navigationId)->exists()) {
            throw ValidationException::withMessages(['navigation' => __('There is no such menu.')]);
        }

        self::check($input->tree);
    }

    protected function perform(OperationInput $input): Navigation
    {
        /** @var SaveNavigationItemsInput $input */
        $navigation = Navigation::query()->lockForUpdate()->findOrFail($input->navigationId);

        $this->replace($navigation, $input->tree);

        // Every page that draws it (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MENU, (string) $navigation->handle, [PageTags::menu((string) $navigation->handle)]));

        return $navigation;
    }

    /**
     * Checks a whole tree as a menu holds it, item by item: at most three
     * levels and 200 items, a known kind, a label of 200 characters at
     * most, a target that exists, an address a menu may hold. Shared with
     * ImportNavigation, so an imported menu meets the same rules.
     *
     * @param  array<mixed>  $tree
     *
     * @throws ValidationException naming each item with a problem
     */
    public static function check(array $tree): void
    {
        $items = self::flatten($tree);
        $problems = [];

        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['tree' => __('A menu holds :count items at most.', ['count' => self::MAX_ITEMS])]);
        }

        foreach ($items as $item) {
            $problem = self::problem($item);

            if ($problem !== null) {
                $problems[$item['key']] = self::named($item, $problem);
            }
        }

        // Targets are checked in one query per kind. IDs are compared as
        // stored, lowercase (HasUlids, I-418).
        foreach (['content' => 'contents', 'term' => 'terms'] as $kind => $table) {
            $wanted = array_filter($items, fn (array $item): bool => $item['kind'] === $kind && $item['target_id'] !== null && ! isset($problems[$item['key']]));
            $found = $wanted === [] ? [] : DB::table($table)->whereIn('id', array_values(array_unique(array_column($wanted, 'target_id'))))->pluck('id')->all();

            foreach ($wanted as $item) {
                if (! in_array($item['target_id'], $found, true)) {
                    $problems[$item['key']] = self::named($item, $kind === 'content'
                        ? __('The page or post it points at no longer exists.')
                        : __('The category or tag it points at no longer exists.'));
                }
            }
        }

        if ($problems !== []) {
            ksort($problems, SORT_NATURAL);

            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * What is wrong with a heading, or null. It has a label of its own and
     * opens the items under it, so it has an item under it at least and
     * never sits at the last level, where none can go (I-2628).
     *
     * @param  array{path: list<int>, label: string|null, children: int}  $item
     */
    private static function headingProblem(array $item): ?string
    {
        if ($item['label'] === null) {
            return __('A heading needs a label.');
        }

        if (count($item['path']) === self::MAX_DEPTH) {
            return __('A heading can’t be at the third level, where nothing can go under it.');
        }

        return $item['children'] === 0 ? __('A heading needs at least one item under it.') : null;
    }

    /**
     * What is wrong with one item on its own, or null. Targets are checked
     * after, all at once.
     *
     * @param  array{path: list<int>, kind: string, target_id: string|null, url: string|null, label: string|null, children: int}  $item
     */
    private static function problem(array $item): ?string
    {
        if (count($item['path']) > self::MAX_DEPTH) {
            return __('Menus go :depth levels deep at most.', ['depth' => self::MAX_DEPTH]);
        }

        if (! in_array($item['kind'], self::KINDS, true)) {
            return __('Choose what this item points at.');
        }

        if ($item['label'] !== null && mb_strlen($item['label']) > 200) {
            return __('The label is 200 characters at most.');
        }

        if ($item['kind'] === 'heading') {
            return self::headingProblem($item);
        }

        if (in_array($item['kind'], ['content', 'term'], true) && $item['target_id'] === null) {
            return __('Choose what this item points at.');
        }

        if ($item['kind'] === 'url' && ! self::allowsAddress($item['url'] ?? '')) {
            return __('The address must start with http://, https://, mailto:, tel: or a single /.');
        }

        return null;
    }

    /**
     * An outside address, an email or phone link, or a path on this site
     * (`//host` would be another site). Nothing that runs, such as javascript:.
     */
    public static function allowsAddress(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/\s/', $url) === 1) {
            return false;
        }

        if (preg_match('#^/(?!/)#', $url) === 1 || preg_match('/^(mailto|tel):.+$/i', $url) === 1) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * The problem with the item's place in front, as "Item 3 › 2: …".
     *
     * @param  array{path: list<int>}  $item
     */
    private static function named(array $item, string $problem): string
    {
        return __('Item :place: :problem', ['place' => implode(' › ', $item['path']), 'problem' => $problem]);
    }
}
