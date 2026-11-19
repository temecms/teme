<?php

/**
 * The Media screen and its Show more, read through MediaLibrary, and
 * uploads, one file per request, through UploadMedia.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Media;

use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Media\Queries\MediaDetails;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Media\Queries\MediaMonths;
use App\Domain\Media\Queries\UploadedForItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

final class MediaLibraryController extends Controller
{
    public function index(Request $request, MediaLibrary $library, MediaMonths $months, SettingsReader $settings, UploadedForItem $items): Response
    {
        [$filters, $item, $zone, $page] = $this->read($request, $library, $settings, $items);

        return Inertia::render('media/index', [
            ...$page,
            'filters' => $filters,
            // The Date select's months, on the site's clock: the trash's on Trash, the live library's otherwise.
            'months' => $months->list(($filters['tab'] ?? 'all') === 'trash', $zone, $filters['month'] ?? null),
            'siteTimezone' => $zone,
            // The item the list is narrowed to, for the chip that names it.
            'uploadedFor' => $item === null ? null : ['id' => $item['id'], 'title' => $item['title']],
            // Each filter chip's count, in the live library.
            'chips' => $library->chips(),
        ]);
    }

    /**
     * Show more: the files after the last one shown, under the same filters
     * and in the same order, with the cursor past them.
     *
     * @since 1.2.0
     */
    public function more(Request $request, MediaLibrary $library, SettingsReader $settings, UploadedForItem $items): JsonResponse
    {
        $page = $this->read($request, $library, $settings, $items)[3];

        return response()->json(['rows' => $page['rows'], 'older' => $page['older']]);
    }

    /**
     * The address's filters, the item they narrow to, the site's time zone
     * and the page of the library they describe.
     *
     * @return array{0: array<string, string>, 1: array{id: string, title: string}|null, 2: string, 3: array{rows: list<array<string, mixed>>, newer: string|null, older: string|null, tabs: list<array{key: string, label: string, count: int}>, filtered: bool}}
     */
    private function read(Request $request, MediaLibrary $library, SettingsReader $settings, UploadedForItem $items): array
    {
        $zone = $settings->string('general', 'timezone');
        $filters = [];

        foreach (['tab', 'type', 'month', 'direction', 'search', 'mine', 'unused', 'missing_alt', 'imported', 'view'] as $key) {
            $value = $request->string($key)->toString();

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        // Only an item that exists narrows the library, so an address naming one deleted since shows it whole (I-553).
        $item = $items->find($request->string('uploaded_for')->toString());

        if ($item !== null) {
            $filters['uploaded_for'] = $item['id'];
        }

        $page = $library->page(new MediaLibraryRequest(
            tab: $filters['tab'] ?? 'all',
            direction: $filters['direction'] ?? 'desc',
            after: $request->string('after')->toString() ?: null,
            before: $request->string('before')->toString() ?: null,
            search: $filters['search'] ?? null,
            mine: $request->boolean('mine'),
            unused: $request->boolean('unused'),
            missingAlt: $request->boolean('missing_alt'),
            type: $filters['type'] ?? null,
            month: $filters['month'] ?? null,
            zone: $zone,
            viewerId: (int) $request->user()?->getAuthIdentifier(),
            uploadedFor: $item['id'] ?? null,
            imported: $request->boolean('imported'),
        ));

        return [$filters, $item === null ? null : ['id' => $item['id'], 'title' => $item['title']], $zone, $page];
    }

    public function store(Request $request, UploadMedia $upload, MediaDetails $details): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');

        try {
            $media = $upload->execute(new UploadMediaInput(
                (string) $file->getRealPath(),
                $file->getClientOriginalName(),
                (string) $request->user()?->getAuthIdentifier(),
                $request->boolean('allow_duplicate'),
                // The item open in the editor, which the upload records when its uploader may edit it (I-553).
                uploadedFor: $request->string('uploaded_for')->toString() ?: null,
            ));
        } catch (DuplicateMediaException $duplicate) {
            $existing = $details->find($duplicate->existingId, $request->user());

            return response()->json(['duplicate' => [
                'id' => $duplicate->existingId,
                'title' => $existing['title'] ?? '',
                'thumbUrl' => $existing['thumbUrl'] ?? null,
            ]], 409);
        }

        return response()->json(['id' => $media->id], 201);
    }
}
