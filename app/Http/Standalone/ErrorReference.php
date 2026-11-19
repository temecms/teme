<?php

/**
 * The reference of one request's failure, such as 7F3A-91C2: written with
 * the error to the log, and shown on the 500 page, so whoever runs the site
 * can find the line someone reports.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Standalone;

use Illuminate\Support\Str;

final class ErrorReference
{
    private ?string $code = null;

    /**
     * This request's reference: eight capital letters and digits in two
     * groups, the same each time it is asked for.
     *
     * @since 1.2.0
     */
    public function code(): string
    {
        return $this->code ??= implode('-', str_split(Str::upper(bin2hex(random_bytes(4))), 4));
    }
}
