<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use App\Domain\Security\OldKeys;
use App\Domain\Security\Operations\Input\ReencryptSecretsInput;
use App\Domain\Security\Operations\ReencryptSecrets;
use App\Domain\Security\ReencryptedSecrets;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;

/**
 * Runs ReencryptSecrets as the operator, as a teme: command does, binding
 * the operator for the call only, so a request after it isn't the operator.
 */
final class Reencrypt
{
    public static function asOperator(OldKeys $keys, int $chunk = 500): ReencryptedSecrets
    {
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            return app(ReencryptSecrets::class)->execute(new ReencryptSecretsInput($keys, $chunk));
        } finally {
            app()->forgetInstance(Elevation::class);
        }
    }
}
