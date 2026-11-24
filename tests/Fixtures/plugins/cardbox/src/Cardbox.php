<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Cardbox;

use Teme\Contracts\Cards\HasCards;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Offers one card, a note, for a theme's regions.
 */
final class Cardbox implements HasCards, Plugin
{
    public function register(Hooks $hooks): void
    {
        //
    }

    public function precedence(): string
    {
        return 'plugin';
    }

    public function cards(): array
    {
        return ['note' => new NoteCard];
    }
}
