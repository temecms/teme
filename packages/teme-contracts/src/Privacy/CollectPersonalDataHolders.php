<?php

/**
 * Gathers the plugins' holders of personal data, asked at each step of a
 * privacy request.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Failure;
use Teme\Contracts\Hooks\HookContract;

/**
 * Gathers the plugins' holders of personal data, asked at each step of a
 * privacy request. A listener that fails stops the request, so an erasure is
 * never reported done while a holder was missed.
 *
 * @since 0.5.0
 */
#[HookContract(failure: Failure::FailOperation)]
final readonly class CollectPersonalDataHolders implements Collect
{
    /** @since 0.5.0 */
    public static function itemType(): string
    {
        return PersonalDataHolder::class;
    }
}
