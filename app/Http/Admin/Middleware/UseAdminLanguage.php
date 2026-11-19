<?php

/**
 * Puts the admin's own words in the person's admin language, whatever the
 * server's locale, so the words PHP builds and the screens' agree.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Languages\AdminWords;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class UseAdminLanguage
{
    public function __construct(private readonly AdminWords $words) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->words->locale($request->user()));

        return $next($request);
    }
}
