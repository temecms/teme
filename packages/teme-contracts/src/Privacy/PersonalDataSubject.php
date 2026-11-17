<?php

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

/**
 * Who a privacy request is about: an email address, lower-cased, and the
 * account it belongs to, when one does.
 *
 * @since 0.5.0
 */
final readonly class PersonalDataSubject
{
    /**
     * @param  string  $email  lower-cased and trimmed by core
     * @param  string|null  $accountId  the account's ID, or null when no account has this address
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $email,
        public ?string $accountId = null,
    ) {}
}
