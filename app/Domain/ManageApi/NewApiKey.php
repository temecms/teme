<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\ManageApi\Models\ApiKey;

/**
 * What CreateApiKey gives back: the key, and its secret, which is never
 * stored and shown only this once.
 */
final readonly class NewApiKey
{
    public function __construct(public ApiKey $key, public string $secret) {}
}
