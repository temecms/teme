<?php

/**
 * The door plugins call when their insertion rules change (I-064). Inside an
 * Operation it notes the change for the page cache to purge after the
 * Operation commits (rule 6): the named items' pages, or the insertions and
 * page-insertions tags. From a plugin's job, which acts for nobody, it runs
 * NotePluginPagesChanged, which does the same after the job's work commits.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\PublicChanges;
use App\Domain\Plugins\Operations\Input\NotePluginPagesChangedInput;
use App\Domain\Plugins\Operations\NotePluginPagesChanged;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Insertions\InsertionsChanged;

final readonly class CoreInsertionsChanged implements InsertionsChanged
{
    /** @param  string|null  $plugin  the plugin this door is given to; null for core's own calls */
    public function __construct(private ?string $plugin = null) {}

    /**
     * @param  list<string>|null  $contentIds  the content items whose pages changed, from a job (since next); null for the whole site
     */
    public function notify(?array $contentIds = null): void
    {
        $changes = app(PublicChanges::class);
        $tags = [PageTags::INSERTIONS, PageTags::PAGE_INSERTIONS];

        if ($changes->inOperation()) {
            $changes->add(...($contentIds === null
                ? [new CacheKeysFor(CacheKeysFor::SETTINGS, 'insertions', $tags)]
                : NotePluginPagesChanged::keysFor($contentIds)));

            return;
        }

        if ($this->plugin === null) {
            $changes->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'insertions', $tags));

            return;
        }

        app(NotePluginPagesChanged::class)->execute(new NotePluginPagesChangedInput($this->plugin, $contentIds, $tags));
    }
}
