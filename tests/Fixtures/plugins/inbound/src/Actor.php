<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Answers /?author=N with ActivityPub-style JSON when Accept asks for it.
 */
final class Actor implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        $hits = app()->bound('inbound.hits') ? app('inbound.hits') : [];
        $hits['actor'] = ($hits['actor'] ?? 0) + 1;
        app()->instance('inbound.hits', $hits);

        $author = $request->query['author'] ?? '0';

        return new PublicResponse(
            200,
            'application/activity+json',
            json_encode(['type' => 'Person', 'id' => $author], JSON_THROW_ON_ERROR),
        );
    }
}
