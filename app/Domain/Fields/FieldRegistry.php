<?php

/**
 * Every field content can carry: declared in code, and the site's own,
 * read from the stored fields at first ask and again when they change
 * (I-3296). It refuses a code field without documentation, a duplicate
 * key, and — once every type is registered — two fields with one handle on
 * the same type, so the generated reference is complete and stored values
 * are never ambiguous.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use InvalidArgumentException;
use Throwable;

final class FieldRegistry
{
    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /** @var array<string, Field> */
    private array $fields = [];

    /** Whether the stored fields' version must be looked at before the next answer. */
    private bool $checkStored = true;

    /** The version of the stored fields read; null until they are. */
    private ?string $storedVersion = null;

    /** @var array<string, list<Field>> the stored live fields, by type, in position order */
    private array $storedLive = [];

    /** @var array<string, list<FieldDefinition>> the stored removed fields, by type, newest removal first */
    private array $storedRemoved = [];

    /** @var array<string, list<string>> every stored key, live, removed and unreadable, by type */
    private array $storedKeys = [];

    /** @var array<string, list<array{key: string, handle: string, reason: string}>> the stored rows that don't read, by type */
    private array $storedUnreadable = [];

    public function register(Field $field): void
    {
        if (! preg_match(self::ULID, $field->key)) {
            throw new InvalidArgumentException("Field [{$field->handle}] needs a ULID key; [{$field->key}] is not one.");
        }

        if (isset($this->fields[$field->key])) {
            throw new InvalidArgumentException("Field key [{$field->key}] is already registered.");
        }

        if (trim($field->label) === '' || trim($field->description) === '' || trim($field->since) === '') {
            throw new InvalidArgumentException("Field [{$field->handle}] must document itself: label, description and since.");
        }

        if ($field->place === FieldPlace::Details && $field->type->edit($field)['component'] === null) {
            throw new InvalidArgumentException("Field [{$field->handle}] is of a type only core’s panels draw, so it can’t be placed in Details.");
        }

        $this->fields[$field->key] = $field;
    }

    /**
     * Throws if two fields that apply to the same type share a handle.
     * Handles are unique per type, not across the site, so two types may
     * each have a field called price. Code's types only: boot reads no
     * database.
     */
    public function verify(ContentTypeRegistry $types): void
    {
        foreach ($types->fromCode() as $type) {
            $seen = [];

            foreach ($this->forDeclaration($type) as $field) {
                if (isset($seen[$field->handle])) {
                    throw new InvalidArgumentException("Content type [{$type->key}] has two fields with the handle [{$field->handle}].");
                }

                $seen[$field->handle] = true;
            }
        }
    }

    /**
     * The fields a type has from its declaration — named for it, or for a
     * feature it supports — asking no registry, so boot may call it.
     *
     * @return list<Field>
     */
    public function forDeclaration(ContentType $type): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $field): bool => in_array($type->key, $field->contentTypes, true)
                || ($field->feature !== null && $type->supports($field->feature)),
        ));
    }

    /**
     * The fields one content type has — named for it, or for a feature it
     * supports — in registration order, then its stored live fields in
     * their order. A type the registry doesn't have gets code's only.
     *
     * @return list<Field>
     */
    public function forType(string $contentType): array
    {
        $types = app(ContentTypeRegistry::class);

        if ($types->has($contentType)) {
            return [...$this->forDeclaration($types->get($contentType)), ...$this->stored($contentType)];
        }

        return array_values(array_filter(
            $this->fields,
            fn (Field $field): bool => in_array($contentType, $field->contentTypes, true),
        ));
    }

    /**
     * The type's stored live fields only, in their order.
     *
     * @return list<Field>
     */
    public function stored(string $contentType): array
    {
        $this->loadStored();

        return $this->storedLive[$contentType] ?? [];
    }

    /**
     * The type's stored removed fields, newest removal first.
     *
     * @return list<FieldDefinition>
     */
    public function removed(string $contentType): array
    {
        $this->loadStored();

        return $this->storedRemoved[$contentType] ?? [];
    }

    /**
     * Every stored key of the type, live and removed, and those of rows that
     * don't read, whose values travel untouched as a removed field's do.
     *
     * @return list<string>
     */
    public function storedKeys(string $contentType): array
    {
        $this->loadStored();

        return $this->storedKeys[$contentType] ?? [];
    }

    /**
     * The type's stored rows that don't read, in position order: each one's
     * key, handle and why it can't be read. Such a field is drawn nowhere.
     *
     * @return list<array{key: string, handle: string, reason: string}>
     */
    public function unreadable(string $contentType): array
    {
        $this->loadStored();

        return $this->storedUnreadable[$contentType] ?? [];
    }

    /**
     * Code's field with this key, or a stored live one; null for any other.
     */
    public function find(string $key): ?Field
    {
        if (isset($this->fields[$key])) {
            return $this->fields[$key];
        }

        $this->loadStored();

        foreach ($this->storedLive as $fields) {
            foreach ($fields as $field) {
                if ($field->key === $key) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Makes the next answer look at the stored fields' version again:
     * another process, or this one, may have changed them.
     */
    public function refreshStored(): void
    {
        $this->checkStored = true;
    }

    /**
     * @return list<Field>
     */
    public function all(): array
    {
        return array_values($this->fields);
    }

    /**
     * Every feature a registered field names, so a type can be checked for
     * supporting only features that give it fields.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return array_values(array_unique(array_filter(array_map(fn (Field $field): ?string => $field->feature, $this->fields))));
    }

    /**
     * Reads the stored fields when their version differs from the one read:
     * each live row as a Field, each removed one as its definition. A row
     * that doesn't read is reported, never thrown, and only its key, handle
     * and reason are kept, from its columns.
     */
    private function loadStored(): void
    {
        if (! $this->checkStored) {
            return;
        }

        $this->checkStored = false;

        try {
            $snapshot = app(StoredFields::class)->snapshot();
        } catch (Throwable $failure) {
            // So the next read tries again, rather than going on without them.
            $this->checkStored = true;

            throw $failure;
        }

        if ($snapshot['version'] === $this->storedVersion) {
            return;
        }

        $live = [];
        $removed = [];
        $keys = [];
        $unreadable = [];

        foreach ($snapshot['definitions'] as $row) {
            try {
                $definition = FieldDefinition::fromRow($row);
                $field = $definition->removedAt === null ? $definition->toField() : null;
            } catch (Throwable $unread) {
                // A Status check that names such a row comes later.
                report($unread);
                $keys[(string) $row['content_type']][] = (string) $row['key'];
                $unreadable[(string) $row['content_type']][] = ['key' => (string) $row['key'], 'handle' => (string) $row['handle'], 'reason' => $unread->getMessage()];

                continue;
            }

            $keys[$definition->type][] = $definition->key;

            if ($field === null) {
                $removed[$definition->type][] = $definition;
            } else {
                $live[$definition->type][] = $field;
            }
        }

        foreach ($removed as $type => $definitions) {
            usort($definitions, fn (FieldDefinition $a, FieldDefinition $b): int => $b->removedAt <=> $a->removedAt);
            $removed[$type] = $definitions;
        }

        $this->storedLive = $live;
        $this->storedRemoved = $removed;
        $this->storedKeys = $keys;
        $this->storedUnreadable = $unreadable;
        $this->storedVersion = $snapshot['version'];
    }
}
