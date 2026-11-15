<?php

declare(strict_types=1);

use App\Domain\PublicRoutes\DeclaredRoute;

it('matches a path’s literal parts literally, and its named parts by their patterns', function (): void {
    $route = new DeclaredRoute(
        owner: 'inbound',
        id: 'feed',
        method: 'GET',
        path: '/{code}/feed.json/{id}',
        handler: 'Handler',
        where: ['id' => '[0-9]+'],
    );

    expect($route->pathParameters('/inbound/feed.json/12'))->toBe(['id' => '12'])
        ->and($route->pathParameters('/inbound/feedxjson/12'))->toBeNull()
        ->and($route->pathParameters('/inbound/feed.json/abc'))->toBeNull()
        ->and($route->pathParameters('/other/feed.json/12'))->toBeNull();
});
