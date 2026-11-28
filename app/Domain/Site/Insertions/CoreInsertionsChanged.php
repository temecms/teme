<?php

/**
 * The door plugins call when their insertion rules change (I-064): inside
 * an Operation, notes the insertions and page-insertions tags for the page
 * cache to purge after the Operation commits (rule 6); from a plugin's job,
 * which acts for nobody, runs NotePluginPagesChanged, which purges the named
 * content items' pages, or the whole site at most once a minute.
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
    public function __construct(
        private PublicChanges $changes,
        private ?string $plugin = null,
    ) {}

    /**
     * @param  list<string>|null  $contentIds
     */
    public function notify(?array $contentIds = null): void
    {
        if ($this->changes->inOperation() || $this->plugin === null) {
            $this->changes->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'insertions', [PageTags::INSERTIONS, PageTags::PAGE_INSERTIONS]));

            return;
        }

        app(NotePluginPagesChanged::class)->execute(new NotePluginPagesChangedInput($this->plugin, $contentIds));
    }
}
