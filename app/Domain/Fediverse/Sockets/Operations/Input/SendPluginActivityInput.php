<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What SendPluginActivity needs: the plugin sending, the activity's type
 * and object, and which of the site's accounts sends it (the blog's when
 * null). Never a person's: actorId is for refusing one.
 */
final readonly class SendPluginActivityInput implements OperationInput
{
    /**
     * @param  array<string, mixed>|string  $object
     */
    public function __construct(
        public string $plugin,
        public string $type,
        public array|string $object,
        public ?string $actorUri = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * The object's address: the object itself when it is one, else its id.
     */
    public function objectUri(): ?string
    {
        $uri = is_string($this->object) ? $this->object : ($this->object['id'] ?? null);

        return is_string($uri) && $uri !== '' ? $uri : null;
    }
}
