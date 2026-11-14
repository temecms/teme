<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * What a listener's failure does, declared by core on the hook, never by
 * the plugin. Only a Transform or a Collect may fail the operation; a
 * Notification goes on, a Gate refuses and a Resolve answers nothing.
 *
 * @since 0.5.0
 */
enum Failure
{
    /** The failing listener is skipped and the rest go on. @since 0.5.0 */
    case SkipListener;

    /** The whole operation fails: for a check whose skipping would let it silently vanish. @since 0.5.0 */
    case FailOperation;
}
