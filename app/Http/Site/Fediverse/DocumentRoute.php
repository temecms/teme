<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Answers a fediverse document (an actor, its outbox, followers or
 * following) at the full address asked for, new or imported; 404 when the
 * site has none there.
 */
final readonly class DocumentRoute implements PublicRoute
{
    public function __construct(private FediverseDocuments $documents) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        $document = $this->documents->at(RequestedAddress::of($request));

        if ($document === null) {
            return new PublicResponse(404, ActivityPub::CONTENT_TYPE, '{}');
        }

        return new PublicResponse(200, ActivityPub::CONTENT_TYPE, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
