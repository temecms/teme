<?php

/**
 * Edit image: one picture large, with its focal point, its crops and its
 * alt text, and its place among the library's images. Saving goes through
 * the media item's own update, so UpdateMediaDetails writes it.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Media;

use App\Domain\Media\Queries\ImagePlace;
use App\Domain\Media\Queries\MediaDetails;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MediaImageController extends Controller
{
    /**
     * @since 1.2.0
     */
    public function __invoke(Request $request, string $media, MediaDetails $details, ImagePlace $places): Response
    {
        $place = $places->of($media);
        $item = $place === null ? null : $details->find($media, $request->user());

        abort_if($place === null || $item === null, 404);

        return Inertia::render('media/image', [
            'item' => $item,
            'place' => $place,
        ]);
    }
}
