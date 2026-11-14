<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;

/**
 * One redirect rule of the old site, read (I-297): which it is, its hash, the redirects it becomes in Teme's form, and what was said of it. No redirect means it is skipped, its first note saying why.
 */
final readonly class OldRule
{
    /**
     * @param  list<array{from: string, to: string, status: int}>  $redirects
     * @param  list<ImportMessage>  $notes
     */
    public function __construct(
        public SourceRef $ref,
        public string $hash,
        public array $redirects,
        public array $notes,
    ) {}
}
