<?php

declare(strict_types=1);

namespace Tests\Support\Media;

use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A folder on disk that damages chosen files as they are written: cut short
 * by a byte, or one byte flipped with the size kept. It stands in for a
 * storage that loses or changes bytes, so a test sees a move's checks
 * catch it.
 */
final class CorruptingAdapter extends LocalFilesystemAdapter
{
    /** @var array<string, 'truncate'|'flip'> */
    private array $damage = [];

    /**
     * Damages the file at $path each time it is written.
     *
     * @param  'truncate'|'flip'  $how
     */
    public function damage(string $path, string $how): self
    {
        $this->damage[$path] = $how;

        return $this;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        parent::write($path, $this->damaged($path, $contents), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        parent::write($path, $this->damaged($path, (string) stream_get_contents($contents)), $config);
    }

    private function damaged(string $path, string $contents): string
    {
        return match ($this->damage[$path] ?? null) {
            'truncate' => substr($contents, 0, -1),
            'flip' => $contents === '' ? $contents : substr($contents, 0, -1).chr(ord($contents[strlen($contents) - 1]) ^ 0xFF),
            default => $contents,
        };
    }
}
