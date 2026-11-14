<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use InvalidArgumentException;

/**
 * Which object of the old site something is: its type and the site's own
 * ID for it, the import map's key (I-332).
 */
final readonly class SourceRef
{
    public function __construct(public string $type, public string $id)
    {
        if (strlen($type) > 40 || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $type) !== 1) {
            throw new InvalidArgumentException("An import object's type [{$type}] must be lower-case words joined by hyphens.");
        }

        if ($id === '' || mb_strlen($id) > 500) {
            throw new InvalidArgumentException("An import object's ID must be 1 to 500 characters.");
        }
    }

    public static function of(string $type, int|string $id): self
    {
        return new self($type, (string) $id);
    }

    /**
     * The type and the ID as one string: `user:42`.
     */
    public function key(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
