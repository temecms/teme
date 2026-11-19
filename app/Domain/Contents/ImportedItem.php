<?php

/**
 * A WordPress post or page as the import decided it should be here
 * (I-332): its type, words, status, dates, author, terms, fields, address
 * and old addresses, for ImportContent or UpdateImportedContent to write.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Comments\Discussion;
use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class ImportedItem
{
    /**
     * @param  string  $status  draft, review, scheduled or published
     * @param  CarbonImmutable|null  $publishedAt  when it was published, or is to be for a scheduled item
     * @param  CarbonImmutable|null  $trashedAt  when WordPress trashed it, or null
     * @param  string|null  $schedulerId  who Teme's scheduler publishes a scheduled item as
     * @param  array<string, mixed>  $document  the converted document, before the normaliser
     * @param  array<string, mixed>  $fields  field key to value, only the type's own fields
     * @param  list<string>  $terms  term IDs, in WordPress's order
     * @param  string|null  $wordpressPath  WordPress's own address, in the path table's form
     * @param  list<string>  $oldSlugs  WordPress's old slugs, as Teme's slug rule keeps them
     * @param  list<string>  $oldDates  WordPress's old local dates, Y-m-d
     * @param  Visibility  $visibility  who may read it once published (I-1700)
     * @param  string|null  $password  the password readers give, only for a protected item
     * @param  string|null  $language  its language as `language` stores it: null for the site's default (I-3446)
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $slug,
        public string $status,
        public ?CarbonImmutable $publishedAt,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $modifiedAt,
        public ?CarbonImmutable $trashedAt,
        public string $authorId,
        public ?string $schedulerId,
        public Discussion $discussion,
        public array $document,
        public array $fields,
        public array $terms,
        public ?string $guid,
        public ?string $wordpressPath,
        public array $oldSlugs,
        public array $oldDates,
        public Visibility $visibility = Visibility::Public,
        #[SensitiveParameter] public ?string $password = null,
        public ?string $language = null,
    ) {}

    /**
     * The same item under another slug: the next free one.
     */
    public function withSlug(string $slug): self
    {
        return new self(
            $this->type, $this->title, $slug, $this->status, $this->publishedAt, $this->createdAt, $this->modifiedAt,
            $this->trashedAt, $this->authorId, $this->schedulerId, $this->discussion, $this->document, $this->fields,
            $this->terms, $this->guid, $this->wordpressPath, $this->oldSlugs, $this->oldDates, $this->visibility, $this->password, $this->language,
        );
    }
}
