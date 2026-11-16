<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use SensitiveParameter;

/**
 * A saved package source as a source kind receives it: its address and,
 * inside the installer only, its decrypted key. A value, never a model, so
 * the source contract carries no Eloquent type.
 */
final readonly class ExtensionSource
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public ?string $url,
        public ?string $username,
        #[SensitiveParameter] public ?string $credential,
    ) {}

    /**
     * The source without its key: what is shown or logged.
     *
     * @return array{id: string, kind: string, label: string, url: ?string, username: ?string}
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'label' => $this->label, 'url' => $this->url, 'username' => $this->username];
    }
}
