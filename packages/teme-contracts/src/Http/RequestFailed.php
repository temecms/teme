<?php

/**
 * Thrown when a request a plugin sent didn't get an answer: it timed out, was
 * refused, its name didn't resolve, or its secure connection failed; or when
 * its body passed 1 MB, so it wasn't sent.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

use RuntimeException;

/**
 * Thrown when a request a plugin sent didn't get an answer: it timed out,
 * was refused, its name didn't resolve, or its secure connection failed; or
 * when its body passed 1 MB, so it wasn't sent.
 *
 * @since 0.5.0
 */
final class RequestFailed extends RuntimeException {}
