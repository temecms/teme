<?php

/**
 * The door a plugin calls when what its cards draw changes: inside an
 * Operation, notes its plugin tag for the page cache to purge after the
 * Operation commits; from the plugin's job, which acts for nobody, runs
 * NotePluginPagesChanged, which purges the named content items' pages, or
 * the whole site at most once a minute.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin;

use App\Domain\Cache\PublicChanges;
use App\Domain\Plugins\Operations\Input\NotePluginPagesChangedInput;
use App\Domain\Plugins\Operations\NotePluginPagesChanged;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Cards\CardsChanged;

/**
 * The door a plugin calls when what its cards draw changes, such as an ad
 * it keeps: every page that drew one of its cards carries the tag
 * "plugin:{code}", purged after the Operation commits (rule 6). Called
 * from a job instead, the same purge runs after the job's work commits.
 */
final readonly class CoreCardsChanged implements CardsChanged
{
    /** @param  string|null  $name  the plugin's name, as the registry keys it; null outside a plugin */
    public function __construct(
        private string $code,
        private ?string $name = null,
    ) {}

    /**
     * @param  list<string>|null  $contentIds
     */
    public function notify(?array $contentIds = null): void
    {
        if (app(PublicChanges::class)->inOperation() || $this->name === null) {
            app(PublicChanges::class)->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin.'.$this->code, ['plugin:'.$this->code]));

            return;
        }

        app(NotePluginPagesChanged::class)->execute(new NotePluginPagesChangedInput($this->name, $contentIds));
    }
}
