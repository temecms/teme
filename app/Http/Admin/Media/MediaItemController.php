<?php

declare(strict_types=1);

namespace App\Http\Admin\Media;

use App\Domain\Ai\Tasks\AltText\Queries\AltTextOffer;
use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\ReplaceMediaFileInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\ReplaceMediaFile;
use App\Domain\Media\Operations\RequestMediaProcessing;
use App\Domain\Media\Operations\RestoreMedia;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Queries\MediaDetails;
use App\Domain\Media\Queries\MediaFileLocation;
use App\Domain\Media\Queries\MediaUsageList;
use App\Http\Controllers\Controller;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One media item from the drawer: its details and where it is used, each
 * change handed to its Operation and answered with the fresh details, and the
 * private original as a download.
 */
final class MediaItemController extends Controller
{
    public function __construct(private readonly MediaDetails $details, private readonly AltTextOffer $altText) {}

    public function show(Request $request, string $media): JsonResponse
    {
        return $this->answer($request, $media);
    }

    public function update(Request $request, string $media, UpdateMediaDetails $update): JsonResponse
    {
        $update->execute(new UpdateMediaDetailsInput(
            mediaId: $media,
            actorId: $this->actor($request),
            title: $request->string('title')->toString(),
            alt: $request->string('alt')->toString(),
            caption: $request->string('caption')->toString(),
            credit: $request->string('credit')->toString(),
            focalX: $request->has('focal_x') ? $request->integer('focal_x') : null,
            focalY: $request->has('focal_y') ? $request->integer('focal_y') : null,
            // Only when sent: a save that doesn't know of the licence keeps it (I-236).
            licence: $request->has('licence') ? $request->string('licence')->toString() : null,
        ));

        return $this->answer($request, $media);
    }

    public function replace(Request $request, string $media, ReplaceMediaFile $replace): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');

        $replace->execute(new ReplaceMediaFileInput($media, (string) $file->getRealPath(), $file->getClientOriginalName(), $this->actor($request)));

        return $this->answer($request, $media);
    }

    public function action(Request $request, string $media, string $action): JsonResponse
    {
        $operation = match ($action) {
            'trash' => app(TrashMedia::class),
            'restore' => app(RestoreMedia::class),
            'retry' => app(RequestMediaProcessing::class),
            default => abort(404),
        };

        $operation->execute(new MediaActionInput($media, $this->actor($request)));

        return $this->answer($request, $media);
    }

    public function destroy(Request $request, string $media, DeleteMediaPermanently $delete): Response
    {
        $delete->execute(new MediaActionInput($media, $this->actor($request)));

        return response()->noContent();
    }

    /**
     * The original as a download: from a bucket, a redirect to a signed
     * address that works for five minutes, made for this request and never
     * kept; from the server's disks, streamed as before (I-3456).
     */
    public function original(string $media, MediaFileLocation $location): StreamedResponse|RedirectResponse
    {
        $file = $location->original($media) ?? abort(404);

        if (! $file['disk'] instanceof LocalFilesystemAdapter && $file['disk']->providesTemporaryUrls()) {
            $url = $file['disk']->temporaryUrl($file['path'], now()->addSeconds((int) config('media.original_link_seconds')), [
                // The slugged name and detected extension, ASCII by construction.
                'ResponseContentDisposition' => 'attachment; filename="'.$file['downloadName'].'"',
            ]);

            return redirect()->away($url, 302)->header('Cache-Control', 'no-store, private');
        }

        return $file['disk']->download($file['path'], $file['downloadName']);
    }

    public function usages(Request $request, string $media, MediaUsageList $usages): JsonResponse
    {
        return response()->json($usages->page($media, $request->string('after')->toString() ?: null, $request->user()));
    }

    private function answer(Request $request, string $media): JsonResponse
    {
        $details = $this->details->find($media, $request->user()) ?? abort(404);

        // Whether this person may ask AI for the image's alt text now (I-321).
        return response()->json([...$details, 'altText' => $this->altText->for($request->user(), $media)]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
