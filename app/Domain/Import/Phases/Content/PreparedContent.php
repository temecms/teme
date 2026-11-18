<?php

/**
 * What the content phase prepares for one item outside its transaction
 * (I-335): the converted document and the old site's addresses it was
 * converted with; nothing for an item it won't convert.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\PreparedObject;

final readonly class PreparedContent implements PreparedObject
{
    public function __construct(
        public ?Conversion $conversion,
        public ?ConversionSite $site,
    ) {}

    /**
     * A conversion holds no file or lock, so there is nothing to free.
     */
    public function release(): void {}
}
