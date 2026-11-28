<?php

/**
 * What the media phase prepared for one attachment before its transaction
 * (I-338): a downloaded file and what to say of it, the reason its file
 * can never come, or nothing to download. Released, it deletes the
 * download, whatever happened; released twice, it does nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Media\Downloads\DownloadedFile;

final readonly class PreparedMedia implements PreparedObject
{
    /**
     * @param  list<ImportMessage>  $warnings
     */
    /**
     * @param  list<ImportMessage>  $warnings
     */
    private function __construct(
        public ?DownloadedFile $file,
        public ?ImportMessage $refusal,
        public array $warnings,
        public bool $fromCopy,
    ) {}

    public static function nothing(): self
    {
        return new self(null, null, [], false);
    }

    public static function refused(ImportMessage $why): self
    {
        return new self(null, $why, [], false);
    }

    /**
     * A file the site's own copy or the old site brought; $fromCopy says which, for the report's counts.
     *
     * @param  list<ImportMessage>  $warnings
     */
    public static function file(DownloadedFile $file, array $warnings = [], bool $fromCopy = false): self
    {
        return new self($file, null, $warnings, $fromCopy);
    }

    public function release(): void
    {
        $this->file?->release();
    }
}
