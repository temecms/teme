<?php

/**
 * Tells the page cache that a running plugin's insertions or cards changed,
 * from its job, which acts for nobody. Named content items have their pages
 * purged after commit. With no items, the door's tags are purged after
 * commit, at most once a minute: a call inside the minute that has already
 * purged is coalesced into one purge when the minute ends, so a change is
 * never dropped. Only a running plugin's job may tell it: a request or a
 * public route of the plugin is refused (rule 1, rule 6).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\PublicChanges;
use App\Domain\Cache\PurgeDeferredPages;
use App\Domain\Import\ImportMode;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\Input\NotePluginPagesChangedInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class NotePluginPagesChanged extends BaseOperation
{
    /** The most content items one notice names. */
    public const int MOST_IDS = 500;

    /** A content ID, a ULID: 26 characters of Crockford base32, in either case. */
    private const string ID_SHAPE = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/i';

    /** The page-cache tags a plugin's whole-site notice may purge. */
    private const array TAGS = ['insertions', 'page-insertions'];

    /** @var list<string>|null the tags a whole-site notice purges, once the commit is in */
    private ?array $wholeSite = null;

    public function __construct(private readonly LoadedPlugins $loaded) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var NotePluginPagesChangedInput $input */
        if (app(CurrentJobActor::class)->get() === null || ! array_key_exists($input->name, $this->loaded->running())) {
            throw new AuthorizationException('Only a running plugin\'s job can tell the page cache its pages changed.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var NotePluginPagesChangedInput $input */
        if ($input->contentIds !== null) {
            self::keysFor($input->contentIds);
        }

        foreach ($input->tags as $tag) {
            if (! in_array($tag, self::TAGS, true) && ! str_starts_with($tag, 'plugin:')) {
                throw ValidationException::withMessages(['purge' => __('Name only the plugin\'s own cache keys.')]);
            }
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var NotePluginPagesChangedInput $input */
        if ($input->contentIds !== null) {
            $this->purgeAfterCommit(...self::keysFor($input->contentIds));

            return true;
        }

        // Taken after the commit, in afterCommit(): a rolled-back notice uses up no minute.
        $this->wholeSite = $input->tags;

        return true;
    }

    /**
     * Takes the minute once the notice has committed: purges now when the
     * minute is free, otherwise coalesces the tags into the one purge queued
     * for the minute's end. An import run holds the purge, as core does.
     */
    protected function afterCommit(mixed $result): void
    {
        if ($this->wholeSite === null) {
            return;
        }

        $tags = $this->wholeSite;

        if (app(ImportMode::class)->active()) {
            app(ImportMode::class)->hold($tags);

            return;
        }

        if (Cache::add(PurgeDeferredPages::WINDOW, true, 60)) {
            app(PublicChanges::class)->purge($tags);

            return;
        }

        $pending = Cache::get(PurgeDeferredPages::PENDING);

        if (! is_array($pending)) {
            Cache::put(PurgeDeferredPages::PENDING, $tags, 120);
            PurgeDeferredPages::dispatch()->delay(now()->addSeconds(60));

            return;
        }

        Cache::put(PurgeDeferredPages::PENDING, array_values(array_unique([...$pending, ...$tags])), 120);
    }

    /**
     * The purge keys for the named content items, each as its page-cache tag.
     *
     * @param  array<mixed>  $contentIds
     *
     * @return list<CacheKeysFor>
     *
     * @throws ValidationException for no item, more than MOST_IDS, or an item that is not a content ID
     */
    public static function keysFor(array $contentIds): array
    {
        if ($contentIds === [] || count($contentIds) > self::MOST_IDS) {
            throw ValidationException::withMessages(['content' => __('Name between 1 and :most content items.', ['most' => self::MOST_IDS])]);
        }

        $keys = [];

        foreach ($contentIds as $id) {
            if (! is_string($id) || preg_match(self::ID_SHAPE, $id) !== 1) {
                throw ValidationException::withMessages(['content' => __('Name the content items as their IDs.')]);
            }

            $keys[] = new CacheKeysFor(CacheKeysFor::CONTENT, $id, [PageTags::content($id)]);
        }

        return $keys;
    }
}
