<?php

declare(strict_types=1);

namespace App\Http\Admin\Media;

use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Media\Queries\MediaMonths;
use App\Domain\Media\Queries\MediaPicker;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The media modal's data: one page of files the caller may choose, of the
 * kinds it allows, as JSON.
 */
final class MediaPickerController extends Controller
{
    public function __invoke(Request $request, MediaPicker $picker, MediaMonths $months, SettingsReader $settings): JsonResponse
    {
        /** @var list<string> $kinds */
        $kinds = array_values(array_filter((array) $request->input('kinds', []), 'is_string'));
        $month = $request->string('month')->toString();
        $month = $month === '' ? null : $month;
        $zone = $settings->string('general', 'timezone');

        return response()->json([
            ...$picker->page(new MediaLibraryRequest(
                after: $request->string('after')->toString() ?: null,
                before: $request->string('before')->toString() ?: null,
                search: $request->string('search')->toString() ?: null,
                unused: $request->boolean('unused'),
                missingAlt: $request->boolean('missingAlt'),
                uploadedFor: $request->string('uploadedFor')->toString() ?: null,
                month: $month,
                zone: $zone,
            ), $kinds !== [] ? $kinds : ['image']),
            // The live library's months on the site's clock, for the modal's Date select, and the zone its dates are shown in.
            'months' => $months->list(false, $zone, $month),
            'siteTimezone' => $zone,
        ]);
    }
}
