<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use RuntimeException;

/**
 * FediverseSender would not send an activity; the message says why.
 *
 * @since 0.9.0
 */
final class SendRefused extends RuntimeException {}
