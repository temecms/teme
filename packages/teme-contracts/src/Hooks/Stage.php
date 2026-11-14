<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * The coarse place a listener takes among a hook's listeners: every early
 * one runs before every normal one, and every normal one before every late
 * one, whatever their plugins name.
 *
 * @since 0.5.0
 */
enum Stage
{
    /** @since 0.5.0 */
    case Early;

    /** @since 0.5.0 */
    case Normal;

    /** @since 0.5.0 */
    case Late;
}
