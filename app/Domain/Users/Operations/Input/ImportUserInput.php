<?php

/**
 * What `ImportUser` needs to bring one WordPress account in as a person:
 * their name, address, author slug, biography and Teme role, the WordPress
 * password hash to keep (null for a random one), the date they joined, and
 * who is importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use App\Domain\Contents\Slugs;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class ImportUserInput implements OperationInput
{
    /** The slug in its composed form, the form a request's address is looked up by. */
    public string $slug;

    public function __construct(
        public string $name,
        public string $email,
        string $slug,
        public ?string $bio,
        public string $role,
        #[SensitiveParameter] public ?string $passwordHash,
        public ?CarbonImmutable $registeredAt,
        public ?string $actorId,
    ) {
        $this->slug = Slugs::normal($slug);
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
