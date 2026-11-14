<?php

declare(strict_types=1);

namespace App\Domain\People\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Visibility;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\People\Operations\Concerns\GivesAccountsPeople;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Sets the people an item credits, in order (I-3366): accounts and people
 * with no account, at most twenty; none, and the item credits its owner.
 * Bylines live on the item, never in a revision, so they show at once. Its
 * locks: the tree, the credited accounts, the item, then the people named.
 */
final class SetBylines extends BaseOperation
{
    use FollowsTree;
    use GivesAccountsPeople;

    protected function authorize(OperationInput $input): void
    {
        /** @var SetBylinesInput $input */
        $content = Content::query()->findOrFail($input->contentId);
        $this->treeType = (string) $content->type;

        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        $user = app(Actors::class)->find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetBylinesInput $input */
        $content = Content::query()->findOrFail($input->contentId);

        if (count($input->credits) > Credits::MOST) {
            throw ValidationException::withMessages(['people' => __('An item can credit at most 20 people.')]);
        }

        if (count(array_unique($input->credits)) !== count($input->credits)) {
            throw ValidationException::withMessages(['people' => __('Each person can be credited once.')]);
        }

        $credits = self::credits($input->credits);
        $accounts = self::accounts($credits);

        foreach ($credits as $key => $credit) {
            $found = $credit !== null && ($credit->kind === Credit::ACCOUNT
                ? DB::table('users')->where('id', (int) $credit->id)->exists()
                : DB::table('people')->where('id', $credit->id)->whereNull('user_id')->exists());

            if (! $found) {
                throw ValidationException::withMessages(['people' => self::noSuchPerson($key)]);
            }
        }

        // Crediting another account is giving the item to someone else's name, as changing its owner is.
        $user = app(Actors::class)->find($input->actorId());
        $others = array_diff($accounts, $user === null ? [] : [(int) $user->id]);

        if ($user !== null && $others !== [] && ! $user->can(app(ContentTypeRegistry::class)->ability($content->type, 'edit.any'))) {
            throw ValidationException::withMessages(['people' => __("Only someone who may edit anyone's :plural may credit another person with an account.", [
                'plural' => mb_strtolower(app(ContentTypeRegistry::class)->get($content->type)->plural),
            ])]);
        }

        // A live item whose address holds {author} moves with a new first credit: its new address must be free.
        $first = self::firstSlug($content, array_values($credits));

        if ($first !== null && $first !== app(Credits::class)->firstSlug((string) $content->id)) {
            $address = Addresses::forContent($content, authorSlug: $first);
            $clash = $address === null ? null : Paths::conflict($address, 'content', (string) $content->id);

            if ($clash !== null) {
                throw ValidationException::withMessages(['people' => $clash]);
            }
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var SetBylinesInput $input */
        // The tree before any row (I-3363).
        $nests = $this->takeTree();
        $credits = array_values(self::credits($input->credits));
        $accounts = self::accounts($credits);

        // The credited accounts before the item, so deleting one of them waits or is waited for, and never crosses (I-3366).
        if ($accounts !== []) {
            $held = DB::select('SELECT id FROM users WHERE id IN ('.implode(', ', array_fill(0, count($accounts), '?')).') ORDER BY id FOR KEY SHARE', $accounts);

            if (count($held) !== count($accounts)) {
                $gone = array_values(array_diff($accounts, array_map(fn (object $row): int => (int) $row->id, $held)));

                throw ValidationException::withMessages(['people' => self::noSuchPerson(Credit::account($gone[0])->key())]);
            }
        }

        $content = Content::query()->lockForUpdate()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);
        $shown = Footprints::content($content);
        $moves = self::usesAuthor($content);
        $firstBefore = $moves ? app(Credits::class)->firstSlug((string) $content->id) : null;
        $this->holdGuests($credits);
        $people = $this->personIdsFor($accounts);

        DB::table('content_bylines')->where('content_id', $content->id)->delete();

        if ($credits !== []) {
            DB::table('content_bylines')->insert(array_map(fn (Credit $credit, int $position): array => [
                'content_id' => (string) $content->id,
                'person_id' => $credit->kind === Credit::ACCOUNT ? $people[(int) $credit->id] : $credit->id,
                'position' => $position,
            ], $credits, array_keys($credits)));
        }

        // Its address holds its first credit's slug: it moves, keeping the old one as a redirect.
        if ($moves && app(Credits::class)->firstSlug((string) $content->id) !== $firstBefore) {
            app(PathProjection::class)->syncContent($content);
            $this->followTree($content, $input->actorId());
        }

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content)]));

        return null;
    }

    /**
     * Locks the people with no account it names, in ID order, after the
     * item's row (people last), and refuses if one was deleted since
     * validate() read it.
     *
     * @param  list<Credit>  $credits
     */
    private function holdGuests(array $credits): void
    {
        $guests = array_values(array_map(fn (Credit $credit): string => $credit->id, array_filter($credits, fn (Credit $credit): bool => $credit->kind === Credit::PERSON)));

        if ($guests === []) {
            return;
        }

        sort($guests);
        $held = array_map(fn (object $row): string => (string) $row->id, DB::select('SELECT id FROM people WHERE id IN ('.implode(', ', array_fill(0, count($guests), '?')).') AND user_id IS NULL ORDER BY id FOR KEY SHARE', $guests));
        $gone = array_values(array_diff($guests, $held));

        if ($gone !== []) {
            throw ValidationException::withMessages(['people' => self::noSuchPerson(Credit::person($gone[0])->key())]);
        }
    }

    /**
     * Each key given, read as the credit it names, or null when it names none.
     *
     * @param  list<string>  $keys
     *
     * @return array<string, Credit|null>
     */
    private static function credits(array $keys): array
    {
        $credits = [];

        foreach ($keys as $key) {
            $credits[$key] = Credit::fromKey($key);
        }

        return $credits;
    }

    /**
     * The accounts among the credits, by ID, in ascending order.
     *
     * @param  array<array-key, Credit|null>  $credits
     *
     * @return list<int>
     */
    private static function accounts(array $credits): array
    {
        $ids = [];

        foreach ($credits as $credit) {
            if ($credit !== null && $credit->kind === Credit::ACCOUNT) {
                $ids[] = (int) $credit->id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Whether the item is live and its address holds {author}.
     */
    private static function usesAuthor(Content $content): bool
    {
        $settings = AddressSettings::current();

        return ! $content->trashed() && $content->status === 'published' && $content->visibility !== Visibility::Private
            && $settings->hasPattern($content->type) && in_array('author', $settings->pattern($content->type)->tokens(), true);
    }

    /**
     * The {author} value the item would take with these credits, for a live
     * item whose address holds it; null otherwise.
     *
     * @param  list<Credit|null>  $credits
     */
    private static function firstSlug(Content $content, array $credits): ?string
    {
        if (! self::usesAuthor($content)) {
            return null;
        }

        $first = $credits[0] ?? null;

        if ($first === null) {
            return Addresses::ownerSlug($content, $content->author_id === null ? null : (int) $content->author_id);
        }

        $slug = $first->kind === Credit::ACCOUNT
            ? DB::table('users')->where('id', (int) $first->id)->value('slug')
            : DB::table('people')->where('id', $first->id)->value('slug');

        return $slug === null ? null : (string) $slug;
    }

    private static function noSuchPerson(string $key): string
    {
        return __("There's no such person: :id.", ['id' => $key]);
    }
}
