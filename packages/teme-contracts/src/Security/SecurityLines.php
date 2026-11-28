<?php

/**
 * Writes a line into the site's security log under the plugin's name.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Security;

use InvalidArgumentException;

/**
 * Writes a line into the security log under the plugin's name, of a kind the
 * plugin declares under `contributes.securityLog` and with security.log
 * granted. A line may name the person it is about, by account ID or by email
 * address, so it reaches that person's data export. Lines are rate-limited
 * per plugin; a refused line is not written, and the result says so.
 *
 * @since next
 */
interface SecurityLines
{
    /**
     * Writes one line, and returns false when the plugin has used up its
     * lines for the hour.
     *
     * @param  string  $kind  one of the kinds the manifest declares
     * @param  string  $text  1 to 200 characters, shown to administrators as it is
     * @param  int|string|null  $userId  the account the line is about, if any
     * @param  string|null  $email  the address the line is about, if no account is named
     *
     * @throws InvalidArgumentException for an undeclared kind, or text that is empty or too long
     *
     * @since next
     */
    public function write(string $kind, string $text, int|string|null $userId = null, ?string $email = null): bool;
}
