<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What `SetUpSite` needs: the setup code, the site's title and time zone, and
 * the first administrator's name, email address and password.
 */
final readonly class SetUpSiteInput implements OperationInput
{
    public function __construct(
        #[SensitiveParameter] public string $code,
        public string $siteTitle,
        public string $timezone,
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $password,
        #[SensitiveParameter] public string $passwordConfirmation,
    ) {}

    /**
     * Nobody is signed in yet: the setup code is the authority.
     */
    public function actorId(): ?string
    {
        return null;
    }
}
