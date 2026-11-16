<?php

declare(strict_types=1);

namespace Teme\Contracts\Notices;

use InvalidArgumentException;

/**
 * Where kinds of notice are declared, as SettingsRegistrar declares settings.
 * A preview until Teme 1.0.
 *
 * @since 0.5.0
 */
interface NoticeRegistrar
{
    /**
     * @throws InvalidArgumentException when the kind breaks a rule: its key's shape, a duplicate, a channel unknown or a default not among them
     *
     * @since 0.5.0
     */
    public function register(NoticeKind $kind): void;
}
