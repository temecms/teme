<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\PublicChanges;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Insertions\InsertionsChanged;

/**
 * The door plugins call when their insertion rules change (I-064): notes
 * the insertions tag for the page cache to purge after the Operation
 * commits (rule 6).
 */
final readonly class CoreInsertionsChanged implements InsertionsChanged
{
    public function __construct(private PublicChanges $changes) {}

    public function notify(): void
    {
        $this->changes->add(new CacheKeysFor(CacheKeysFor::SETTINGS, 'insertions', [PageTags::INSERTIONS]));
    }
}
