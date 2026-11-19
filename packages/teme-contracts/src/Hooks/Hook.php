<?php

/**
 * What every hook is: a class that is its own name, its payload and its
 * contract.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * What every hook is: a class that is its own name, its payload and its
 * contract. A hook also implements exactly one of the five verbs,
 * Notification, Transform, Gate, Resolve or Collect, which say how its
 * listeners are called and what the dispatch gives back.
 *
 * @since 0.5.0
 */
interface Hook {}
