<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\Queries\FediverseDocuments;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Sends a browser at one of the WordPress plugin's fediverse addresses,
 * /?author=N or /?p=N, to the page a person reads: the author's page, the
 * item, or the home page.
 */
final readonly class BrowserRoute implements PublicRoute
{
    public function __construct(private FediverseDocuments $documents) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        return new PublicResponse(301, 'text/plain; charset=utf-8', '', ['Location' => $this->documents->browserAddress(RequestedAddress::of($request))]);
    }
}
