<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Concerns;

use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Who may record a restore: the operator at the server alone, since in
 * phase 1 a site is restored there (I-029).
 */
trait AuthorizesRestores
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException(RestoreSite::OPERATOR_ONLY);
        }
    }
}
