<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Closure;

/**
 * Says whether Teme's commands run as root (I-378): files they write under
 * storage/ then belong to a user the web server may not be.
 */
final readonly class RunningUser implements DoctorCheck
{
    /**
     * @param  (Closure(): ?int)|null  $userId  gives the user's ID, or null when it can't be told; PHP's own when not given
     */
    public function __construct(private ?Closure $userId = null) {}

    public function title(): string
    {
        return 'User';
    }

    public function examine(): array
    {
        $id = $this->userId !== null
            ? ($this->userId)()
            : (function_exists('posix_geteuid') ? posix_geteuid() : null);

        if ($id === null) {
            return [new Finding(Severity::Info, "Can't tell which user this runs as: PHP's posix extension is missing.")];
        }

        if ($id === 0) {
            return [new Finding(Severity::inProduction(app()->isProduction()), "This runs as root, so files it writes under storage/ may be ones the web server can't change.", "Run Teme's commands as the user the web server runs as, as docker compose exec does by default.")];
        }

        return [new Finding(Severity::Ok, "Runs as user {$id}, not root.")];
    }
}
