<?php

/**
 * What one export run shares with its kinds: the folder it writes, the tally
 * of media originals copied, and the page size a test set (I-365).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

final readonly class ExportContext
{
    public function __construct(public ExportFolder $folder, public MediaTally $media, public ?int $pageSize = null) {}

    /** The run's page size when it set one, else the kind's own. */
    public function pageSize(int $default): int
    {
        return $this->pageSize ?? $default;
    }
}
