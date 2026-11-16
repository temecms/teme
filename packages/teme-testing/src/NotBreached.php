<?php

declare(strict_types=1);

namespace Teme\Testing;

use Illuminate\Contracts\Validation\UncompromisedVerifier;

/**
 * Answers that no password was ever breached, while the kit makes a person,
 * so a test sends nothing to Have I Been Pwned.
 */
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
