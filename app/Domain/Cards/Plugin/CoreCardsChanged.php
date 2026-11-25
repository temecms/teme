<?php

/**
 * The door a plugin calls when what its cards draw changes: notes its
 * plugin tag for the page cache to purge after the Operation commits.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin;

use App\Domain\Cache\PublicChanges;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Cards\CardsChanged;

/**
 * The door a plugin calls when what its cards draw changes, such as an ad
 * it keeps: every page that drew one of its cards carries the tag
 * "plugin:{code}", purged after the Operation commits (rule 6).
 */
final readonly class CoreCardsChanged implements CardsChanged
{
    public function __construct(private string $code) {}

    public function notify(): void
    {
        app(PublicChanges::class)->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin.'.$this->code, ['plugin:'.$this->code]));
    }
}
