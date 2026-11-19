<?php

/**
 * Whether a model reasons before it answers, and how far Teme lets it for its
 * tasks (I-1360). Not a capability: it changes how long a request takes and
 * what it costs, never which tasks a model may do.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

enum Reasoning: string
{
    /** The model answers without reasoning. */
    case None = 'none';

    /** The model reasons; Teme asks for the least reasoning it accepts. */
    case Lowest = 'lowest';

    /** The model can reason; Teme switches it off for its tasks. */
    case Off = 'off';

    /**
     * What Settings › AI says beside the model, or null when it needs nothing.
     */
    public function note(): ?string
    {
        return match ($this) {
            self::Lowest => 'Reasons before answering: slower, and costs more.',
            self::None, self::Off => null,
        };
    }
}
