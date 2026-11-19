<?php

/**
 * Thrown when a plugin subscribes to something it may not: a class that isn't
 * a hook, a hook its teme-plugin.json doesn't list, a hook whose capability it
 * wasn't granted, or any hook once its register() is over.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

use LogicException;

/**
 * Thrown when a plugin subscribes to something it may not: a class that
 * isn't a hook, a hook its teme-plugin.json doesn't list, a hook whose
 * capability it wasn't granted, or any hook once its register() is over.
 *
 * @since 0.5.0
 */
final class HookRefused extends LogicException {}
