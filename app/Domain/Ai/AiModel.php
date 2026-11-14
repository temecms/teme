<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * One model a provider offers: its own name for it, the name people read,
 * what it can do (I-887) and, when Teme knows it, its price.
 */
final readonly class AiModel
{
    /**
     * @param  list<AiCapability>  $capabilities
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $capabilities,
        public ?AiPrice $price = null,
    ) {
        if ($capabilities === []) {
            throw new InvalidArgumentException("AI model [{$id}] needs a capability.");
        }

        if (trim($id) === '' || trim($label) === '') {
            throw new InvalidArgumentException("AI model [{$id}] needs a name and a label.");
        }
    }

    /**
     * Whether it has every capability needed; true when none is.
     */
    public function can(AiCapability ...$needed): bool
    {
        foreach ($needed as $capability) {
            if (! in_array($capability, $this->capabilities, true)) {
                return false;
            }
        }

        return true;
    }
}
