<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * A hook that tells listeners what happened. A listener is
 * `fn (TheHook $hook): void`; what it returns is ignored, and a listener
 * that fails never stops the others.
 *
 * @since 0.5.0
 */
interface Notification extends Hook {}
