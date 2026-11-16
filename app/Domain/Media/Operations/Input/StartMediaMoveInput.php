<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

/**
 * What StartMediaMove needs: the run's kind and direction, the roles and
 * items it covers, the site's disks as it starts, and the run to resume,
 * if any. Only the operator, with no actor, may start one.
 */
final readonly class StartMediaMoveInput implements OperationInput
{
    /**
     * @param  'copy'|'delete_source'  $kind
     * @param  list<'public'|'originals'>  $roles
     * @param  'all'|'changed'  $scope
     * @param  array{public: string, originals: string}  $siteDisks
     */
    public function __construct(
        public string $kind,
        public string $from,
        public string $to,
        public array $roles,
        public string $scope,
        public ?CarbonImmutable $changedSince,
        public bool $deep,
        public array $siteDisks,
        public int $itemsTotal,
        public ?string $resumeId = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
