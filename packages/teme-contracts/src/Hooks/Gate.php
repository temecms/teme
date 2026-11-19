<?php

/**
 * A hook that lets listeners refuse something before it happens.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A hook that lets listeners refuse something before it happens. A listener
 * is `fn (TheHook $hook): ?Veto`; the first Veto stops it, and a listener
 * that fails refuses it too, to be safe.
 *
 * @since 0.5.0
 */
interface Gate extends Hook {}
