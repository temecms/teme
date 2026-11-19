<?php

/**
 * Answers a fediverse document (an actor, its outbox, followers or
 * following, a post's object) at the full address asked for, new or
 * imported; 410 with a Tombstone for a post taken back, 404 when the site
 * has none there.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

final readonly class DocumentRoute implements PublicRoute
{
    public function __construct(private FediverseDocuments $documents) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        $document = $this->documents->at(RequestedAddress::of($request));

        if ($document === null) {
            return new PublicResponse(404, ActivityPub::CONTENT_TYPE, '{}');
        }

        $status = ($document['type'] ?? null) === 'Tombstone' ? 410 : 200;

        return new PublicResponse($status, ActivityPub::CONTENT_TYPE, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
