<?php

/**
 * Answers that no password was ever breached, while the kit makes a person,
 * so a test sends nothing to Have I Been Pwned.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use Illuminate\Contracts\Validation\UncompromisedVerifier;

final readonly class NotBreached implements UncompromisedVerifier
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function verify($data): bool
    {
        return true;
    }
}
