<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\Queries\FediverseDocuments;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * WebFinger (RFC 7033) for the site's actors: acct:name@host, or an
 * actor's own address, answers the actor's address and its page.
 */
final readonly class WebFingerRoute implements PublicRoute
{
    public function __construct(private FediverseDocuments $documents) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        $answer = $this->documents->webFinger($request->query['resource'] ?? '');

        if ($answer === null) {
            return new PublicResponse(404, 'application/jrd+json', '{}');
        }

        return new PublicResponse(200, 'application/jrd+json', json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
