<?php

/**
 * Tells the page cache that a running plugin's insertions or cards changed,
 * from a job that acts for nobody: the pages of the named content items are
 * purged after commit, or, named for no item, the whole site, at most once
 * a minute, as a site-wide purge warms pages and queues the CDN. Only a
 * running plugin may tell it (rule 1: this is the one place a job reaches
 * the public cache).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Cache\PageTags;
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
    /** The cache key that holds the whole-site purge's minute. */
    private const string SITE_MINUTE = 'plugins.pages-changed.site';

    public function __construct(private readonly LoadedPlugins $loaded) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var NotePluginPagesChangedInput $input */
        if (! array_key_exists($input->name, $this->loaded->running())) {
            throw new AuthorizationException('Only a running plugin can tell the page cache its pages changed.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var NotePluginPagesChangedInput $input */
        if ($input->contentIds !== null && ($input->contentIds === [] || array_filter($input->contentIds, fn (string $id): bool => $id === '') !== [])) {
            throw ValidationException::withMessages(['content' => [__('Name the content items as their IDs.')]]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var NotePluginPagesChangedInput $input */
        if ($input->contentIds !== null) {
            $this->purgeAfterCommit(...array_map(
                static fn (string $id): CacheKeysFor => new CacheKeysFor(CacheKeysFor::CONTENT, $id, [PageTags::content($id)]),
                $input->contentIds,
            ));

            return true;
        }

        // Once a minute at most: a second job's note in the same minute waits for the next purge, as a site-wide one costs a warm of every page.
        if (! Cache::add(self::SITE_MINUTE, true, 60)) {
            return false;
        }

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));

        return true;
    }
}
