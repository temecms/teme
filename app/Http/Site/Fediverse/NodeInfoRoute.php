<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\Queries\FediverseDocuments;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * NodeInfo: at /.well-known/nodeinfo the link to the document, and at
 * /nodeinfo/2.1 the document, which tells other servers this is Teme
 * speaking ActivityPub.
 */
final readonly class NodeInfoRoute implements PublicRoute
{
    public const string SCHEMA = 'http://nodeinfo.diaspora.software/ns/schema/2.1';

    public function __construct(private FediverseDocuments $documents) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        if (str_starts_with($request->path, '/.well-known/')) {
            $links = ['links' => [['rel' => self::SCHEMA, 'href' => rtrim(url('/'), '/').'/nodeinfo/2.1']]];

            return new PublicResponse(200, 'application/json', json_encode($links, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return new PublicResponse(200, 'application/json; profile="'.self::SCHEMA.'#"', json_encode($this->documents->nodeInfo(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
