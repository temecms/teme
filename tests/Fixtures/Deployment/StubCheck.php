<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use Throwable;

/**
 * A doctor's check whose title and findings a test chooses, or that throws
 * what the test gives it. The test binds an instance in the container,
 * which the Doctor asks for by class.
 */
class StubCheck implements DoctorCheck
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(
        private readonly string $title,
        private readonly array $findings,
        private readonly ?Throwable $throws = null,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function examine(): array
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->findings;
    }
}
