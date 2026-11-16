<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

/**
 * One list column a running plugin offers for a content type: the key the
 * person's Columns preference stores, and the facts the list page draws.
 */
final readonly class DeclaredColumn
{
    public function __construct(
        public string $key,
        public string $owner,
        public string $code,
        public string $id,
        public string $label,
        public string $plugin,
        public string $kind,
        public int $width,
    ) {}

    /**
     * The shape the content list page receives for this column.
     *
     * @return array{key: string, label: string, plugin: string, kind: string, width: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'plugin' => $this->plugin,
            'kind' => $this->kind,
            'width' => $this->width,
        ];
    }
}
