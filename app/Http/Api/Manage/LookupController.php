<?php

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\ManageApi\Queries\CreditablePeople;
use App\Domain\ManageApi\Queries\TermChoices;
use Illuminate\Http\JsonResponse;

/**
 * What an outside tool looks up before it writes: a taxonomy's terms, and
 * the people an item may be credited to. Both need site.read.
 */
final class LookupController
{
    public function terms(string $taxonomy, TermChoices $terms): JsonResponse
    {
        return ManageAnswer::data($terms->of($taxonomy));
    }

    public function people(CreditablePeople $people): JsonResponse
    {
        return ManageAnswer::data($people->all());
    }
}
