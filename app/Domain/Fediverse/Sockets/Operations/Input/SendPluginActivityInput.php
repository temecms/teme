<?php

/**
 * What SendPluginActivity needs: the plugin sending, the activity's type
 * and object, which of the site's accounts sends it (the blog's when
 * null), and the person the plugin acts for.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
