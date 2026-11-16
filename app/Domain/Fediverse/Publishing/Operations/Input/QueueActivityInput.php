<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing\Operations\Input;

use App\Domain\Fediverse\Publishing\SiteWork;
use Teme\Contracts\Operations\OperationInput;

/**
 * What QueueActivity needs: the item, and whether it was published (sent
 * as Create or Update) or left the public site (sent as Delete).
 */
final readonly class QueueActivityInput implements OperationInput
{
    public const string PUBLISHED = 'published';

    public const string REMOVED = 'removed';

    public function __construct(
        public string $contentId,
        public string $change,
        public ?string $actorId = null,
        public ?SiteWork $siteWork = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
