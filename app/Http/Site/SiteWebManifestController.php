<?php

/**
 * /site.webmanifest: the JSON web app manifest with the site's 192 and 512
 * icons from the design site_icon (issue 388).
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\Queries\SiteWebManifest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class SiteWebManifestController extends Controller
{
    public function __invoke(SiteWebManifest $manifest): JsonResponse
    {
        return response()->json($manifest->data(), 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
        ]);
    }
}
