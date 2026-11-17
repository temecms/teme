<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * Lets plugins change one of the site's accounts as other servers read
 * it, such as adding profile fields. Teme puts back the keys that say who
 * it is and where it is reached (`id`, `type`, `preferredUsername`,
 * `inbox`, `outbox`, `followers`, `following`, `endpoints`, `publicKey`)
 * as it wrote them.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class ActorDocumentBuilt implements Transform
{
    /**
     * @param  array<string, mixed>  $document  the account's document as Teme built it
     * @param  string  $actorUri  the account's address
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $document,
        public string $actorUri,
    ) {}

    /** @since 1.0.0 */
    public static function valueType(): string
    {
        return 'array';
    }

    /** @since 1.0.0 */
    public function value(): mixed
    {
        return $this->document;
    }
}
