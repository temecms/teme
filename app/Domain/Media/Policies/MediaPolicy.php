<?php

/**
 * Decides who may change or remove a media item: anyone holding the "any"
 * permission, and uploaders for their own files. The media Operations ask it
 * before they write.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Policies;

use App\Domain\Media\Models\Media;
use App\Models\User;

final class MediaPolicy
{
    public function update(User $user, Media $media): bool
    {
        return $user->can('media.edit.any')
            || ($user->can('media.edit.own') && $media->uploaded_by === $user->id);
    }

    public function delete(User $user, Media $media): bool
    {
        return $user->can('media.delete.any')
            || ($user->can('media.delete.own') && $media->uploaded_by === $user->id);
    }
}
