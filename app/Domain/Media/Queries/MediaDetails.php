<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Contents\Queries\PrivateTitles;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Everything the media drawer shows about one item, and what the viewer may
 * do with it.
 */
final class MediaDetails
{
    public function __construct(private readonly UploadedForItem $items) {}

    /**
     * The item's details, file URLs and use counts, and whether the viewer
     * may edit or remove it; null if there is no such item.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, User $viewer): ?array
    {
        $media = Media::withTrashed()->find($id);

        if ($media === null) {
            return null;
        }

        $uploadedFor = $this->items->find($media->uploaded_for);

        if ($uploadedFor !== null) {
            // A private item's title only for whoever may edit it (I-1700).
            $uploadedFor['title'] = PrivateTitles::of($uploadedFor['id'], $uploadedFor['title'], $viewer);
        }

        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'title' => $media->title,
            'filename' => $media->original_filename,
            'extension' => $media->extension,
            'mimeType' => $media->mime_type,
            'width' => $media->width,
            'height' => $media->height,
            'sizeBytes' => $media->size_bytes,
            'alt' => $media->alt,
            'caption' => $media->caption,
            'credit' => $media->credit,
            'licence' => $media->licence,
            'focalX' => $media->focal_x,
            'focalY' => $media->focal_y,
            'thumbUrl' => MediaUrls::variant($media->variants),
            'previewUrl' => MediaUrls::largest($media->variants),
            'fileUrl' => $media->isImage() ? null : MediaDisks::public()->url($media->original_path),
            'processing' => $media->processing,
            'processingError' => $media->processing_error,
            'workingUses' => $media->working_uses,
            'publishedUses' => $media->published_uses,
            'trashed' => $media->trashed(),
            'trashedAt' => $media->deleted_at?->toIso8601String(),
            // The item it was uploaded for, apart from where it is used (I-553).
            'uploadedFor' => $uploadedFor,
            'createdAt' => $media->created_at?->toIso8601String(),
            'updatedAt' => $media->updated_at?->toIso8601String(),
            // Whose picture it is, counted only: names are People's to show (I-3410).
            'pictureOf' => DB::table('users')->where('picture_media_id', $media->id)->count(),
            'uploaderName' => $media->uploaded_by === null ? null : User::query()->whereKey($media->uploaded_by)->value('name'),
            'can' => [
                'edit' => Gate::forUser($viewer)->allows('update', $media),
                'delete' => Gate::forUser($viewer)->allows('delete', $media),
            ],
        ];
    }
}
