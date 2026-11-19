<?php

/**
 * GET /api/manage/v1/me: who the key is, the person it acts as, its
 * abilities and the site it signed in to.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\ManageApi\Queries\CallerSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request, CallerSummary $summary): JsonResponse
    {
        return ManageAnswer::data($summary->of(ManageRequest::caller($request)));
    }
}
