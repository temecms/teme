<?php

/**
 * What the import made of the old site, already known: each answer an array
 * lookup. What a test gives the converter, or a phase that read a page's
 * answers at once (I-1702).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

final readonly class KnownTargets implements SourceTargets
{
    /**
     * @param  array<string, array{kind: string, id: string}>  $pages  by page key, as ConversionSite::pageKey() makes one
     * @param  array<int, string>  $posts  WordPress post ID to Teme article ID
     * @param  array<string, string>  $terms  `{taxonomy}:{term ID}` to Teme term ID
     * @param  array<string, string>  $termSlugs  `{taxonomy}:{slug}` to Teme term ID
     * @param  array<int, string>  $users  WordPress user ID to Teme person ID
     * @param  array<string, string>  $userSlugs  nicename to Teme person ID
     * @param  array<string, string>  $outside  an image's address on another site to its media ID
     * @param  array<string, list<int>>  $attached  `{post ID}:{type}` to the attached files' WordPress IDs
     * @param  bool  $oldSiteMedia  whether own images without a library file stay as links to the old site
     */
    public function __construct(
        private array $pages = [],
        private array $posts = [],
        private array $terms = [],
        private array $termSlugs = [],
        private array $users = [],
        private array $userSlugs = [],
        private array $outside = [],
        private array $attached = [],
        private bool $oldSiteMedia = false,
        private bool $drawsOldSiteImages = false,
    ) {}

    public function page(string $key): ?array
    {
        return $this->pages[$key] ?? null;
    }

    public function post(int $id): ?string
    {
        return $this->posts[$id] ?? null;
    }

    public function term(string $taxonomy, int $id): ?string
    {
        return $this->terms["{$taxonomy}:{$id}"] ?? null;
    }

    public function termNamed(string $taxonomy, string $slug): ?string
    {
        return $this->termSlugs["{$taxonomy}:{$slug}"] ?? null;
    }

    public function user(int $id): ?string
    {
        return $this->users[$id] ?? null;
    }

    public function userNamed(string $nicename): ?string
    {
        return $this->userSlugs[$nicename] ?? null;
    }

    public function outsideImage(string $address): ?string
    {
        return $this->outside[$address] ?? null;
    }

    public function keepsOldSiteMedia(): bool
    {
        return $this->oldSiteMedia;
    }

    public function drawsOldSiteImages(): bool
    {
        return $this->drawsOldSiteImages;
    }

    public function attached(int $postId, string $type): array
    {
        return $this->attached["{$postId}:{$type}"] ?? [];
    }
}
