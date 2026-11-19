<?php

/**
 * Joins items, and every member of their translation groups, into one
 * group (I-3360, D10): the first named item's group that has one, else a
 * new one. Refused when two would share a language, when the types
 * differ, or when one is in the trash. Answers the group's ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\GroupLock;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\MovesVersionAddresses;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class LinkTranslations extends BaseOperation
{
    use MovesVersionAddresses;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var LinkTranslationsInput $input */
        $actor = User::query()->find($input->actorId());

        if ($actor === null) {
            throw new AuthorizationException('Not permitted to link these translations.');
        }

        foreach (Content::withTrashed()->whereIn('id', $input->contentIds)->get() as $item) {
            if (! Gate::forUser($actor)->allows('update', $item)) {
                throw new AuthorizationException('Not permitted to link these translations.');
            }
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var LinkTranslationsInput $input */
        $most = count(app(SiteLanguages::class)->all());
        $ids = $input->contentIds;

        if (count($ids) < 2 || count($ids) > $most || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['items' => __('Choose between 2 and :most different items.', ['most' => $most])]);
        }

        $found = Content::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');
        // In the order named: the first names the type and the group kept.
        $named = collect($ids)->mapWithKeys(fn (string $id): array => [$id => $found->get($id)])->filter();

        if ($named->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => __('Choose between 2 and :most different items.', ['most' => $most])]);
        }

        TranslatedTypes::refuseType((string) $named->first()?->type, key: 'items');
        $groups = $named->pluck('translation_group')->filter()->unique()->values()->all();
        $members = $groups === [] ? collect() : Content::withTrashed()->whereIn('translation_group', $groups)->get();
        self::refuse($named, $members->keyBy('id')->merge($named));
    }

    protected function perform(OperationInput $input): string
    {
        /** @var LinkTranslationsInput $input */
        LanguageLock::shared();
        $this->takeTrees($input->contentIds);
        $rows = GroupLock::lock($input->contentIds);
        $named = collect($input->contentIds)->mapWithKeys(fn (string $id): array => [$id => $rows->get($id)])->filter();
        TranslatedTypes::refuseType((string) $named->first()?->type, fromRow: true, key: 'items');
        self::refuse($named, $rows);

        $before = $rows->pluck('translation_group')->filter()->unique()->values()->all();
        $group = $named->pluck('translation_group')->filter()->first() ?? TranslationGroups::newId();

        DB::table('contents')->whereIn('id', $rows->keys()->all())->update(['translation_group' => $group]);
        $this->moveVersionAddresses($rows, 'items');

        $this->purgeAfterCommit(...self::translationKeys($rows, [...$before, $group]));

        return (string) $group;
    }

    /**
     * Refuses a named item in the trash, items of different types, and two
     * live members, of all the groups joined, in one language.
     *
     * @param  Collection<array-key, Content>  $named
     * @param  Collection<array-key, Content>  $members
     *
     * @throws ValidationException
     */
    private static function refuse(Collection $named, Collection $members): void
    {
        if ($named->contains(fn (Content $item): bool => $item->trashed())) {
            throw ValidationException::withMessages(['items' => __('Restore it first.')]);
        }

        $types = $named->pluck('type')->unique();

        if ($types->count() > 1) {
            $first = app(ContentTypeRegistry::class)->get((string) $named->first()?->type);

            throw ValidationException::withMessages(['items' => __('Choose :plural: a translation is of the same type.', ['plural' => mb_strtolower($first->plural)])]);
        }

        $shared = $members->reject(fn (Content $item): bool => $item->trashed())
            ->groupBy(fn (Content $item): string => (string) $item->language)
            ->first(fn (Collection $same): bool => $same->count() > 1);

        if ($shared !== null) {
            $languages = app(SiteLanguages::class);

            throw ValidationException::withMessages(['items' => __('Both would be in :language. Each language can have one version.', ['language' => $languages->label($languages->of($shared->first()?->language))])]);
        }
    }
}
