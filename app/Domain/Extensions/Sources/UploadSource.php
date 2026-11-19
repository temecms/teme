<?php

/**
 * A ZIP someone uploaded: kept as data until the installer unpacks it into
 * the extensions folder's packages/, where it stays unchanged, and Composer
 * reads every package there as a path repository, copied, never linked (I-211).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use App\Domain\Extensions\Models\ExtensionUploadRecord;
use RuntimeException;

final readonly class UploadSource implements SourceKind
{
    public const string KEY = 'upload';

    public function __construct(private ArchiveReader $reader) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [[
            'type' => 'path',
            'url' => rtrim($extensionsPath, '/').'/packages/*/*/*',
            'options' => ['symlink' => false],
        ]];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void
    {
        $upload = $request->uploadId === null ? null : ExtensionUploadRecord::query()->find($request->uploadId);

        if ($upload === null) {
            throw new RuntimeException(__('The uploaded file for this request is gone. Upload it again, then ask again.'));
        }

        if ($upload->package !== $request->package) {
            throw new RuntimeException(__('The upload holds :upload, but the request is for :package.', ['upload' => $upload->package, 'package' => (string) $request->package]));
        }

        $this->reader->unpack($upload->archive(), $upload->sha256, $upload->package, $upload->version, $request->kind, rtrim($extensionsPath, '/').'/packages');
    }
}
