<?php

/**
 * Thrown when a remote host's answer passes 1 MB.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

use RuntimeException;

/**
 * Thrown when a remote host's answer passes 1 MB.
 *
 * @since 0.5.0
 */
final class AnswerTooLarge extends RuntimeException {}
