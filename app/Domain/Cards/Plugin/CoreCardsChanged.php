<?php

/**
 * The door a plugin calls when what its cards draw changes. Inside an
 * Operation it notes its plugin tag, or the named items' pages, for the page
 * cache to purge after the Operation commits. From the plugin's job, which
 * acts for nobody, it runs NotePluginPagesChanged, which does the same after
 * the job's work commits.
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
 * "plugin:{code}", purged after the Operation commits (rule 6). Called from
 * a job instead, the same purge runs after the job's work commits.
 */
final readonly class CoreCardsChanged implements CardsChanged
{
    /** @param  string|null  $name  the plugin's name, as the registry keys it; null outside a plugin */
    public function __construct(
        private string $code,
        private ?string $name = null,
    ) {}

    /**
     * @param  list<string>|null  $contentIds  the content items whose pages changed, from a job (since next); null for the plugin's tag
     */
    public function notify(?array $contentIds = null): void
    {
        $changes = app(PublicChanges::class);
        $tag = 'plugin:'.$this->code;

        if ($changes->inOperation()) {
            $changes->add(...($contentIds === null
                ? [new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin.'.$this->code, [$tag])]
                : NotePluginPagesChanged::keysFor($contentIds)));

            return;
        }

        if ($this->name === null) {
            $changes->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin.'.$this->code, [$tag]));

            return;
        }

        app(NotePluginPagesChanged::class)->execute(new NotePluginPagesChangedInput($this->name, $contentIds, [$tag]));
    }
}
