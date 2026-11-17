<?php

declare(strict_types=1);

namespace Teme\Contracts\Notices;

use InvalidArgumentException;

/**
 * Sends a notice of a declared kind. Core decides who receives it: the
 * site's switch, each person's choice and the kind's audience; it is
 * delivered after the current database transaction commits, never if it
 * rolls back.
 *
 * @since 0.5.0
 */
interface Notices
{
    /**
     * To accounts, by ID; an account that can't receive it is passed over.
     *
     * @param  list<string>  $accountIds
     *
     * @throws InvalidArgumentException for an unregistered kind or an action naming no route
     *
     * @since 0.5.0
     */
    public function send(string $kind, array $accountIds, NoticeMessage $message): void;

    /**
     * To an email address with no account behind it, for a required kind only.
     *
     * @throws InvalidArgumentException for a kind that isn't required or doesn't send email
     *
     * @since 0.5.0
     */
    public function sendToAddress(string $kind, string $email, NoticeMessage $message): void;
}
