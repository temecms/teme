<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Media\Models\Media;
use App\Domain\Site\SiteLanguage;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Media\MediaRef;

/**
 * Builds a hook's plain values from core's own (I-3353): never a model, a
 * collection or a framework date, which would tie every plugin to
 * Laravel's version (rule 5).
 */
final class Payloads
{
    /**
     * Returns the item as hooks tell of it, with its live address read now
     * and its language as the public page's `lang` says it (I-3360).
     */
    public static function item(Content $content): ItemRef
    {
        return new ItemRef(
            (string) $content->id,
            (string) $content->type,
            (string) $content->title,
            (string) $content->status,
            Addresses::urlFor($content),
            app(SiteLanguage::class)->forContent($content),
        );
    }

    /**
     * Returns the media item as hooks tell of it.
     */
    public static function media(Media $media): MediaRef
    {
        return new MediaRef(
            (string) $media->id,
            $media->kind,
            $media->mime_type,
            $media->original_filename,
            $media->title,
            $media->size_bytes,
            $media->width,
            $media->height,
        );
    }

    /**
     * Returns the moment as PHP's own DateTimeImmutable, in a zone named UTC.
     */
    public static function date(CarbonInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
    }
}
