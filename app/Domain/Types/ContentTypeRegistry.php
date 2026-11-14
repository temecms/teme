<?php

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Comments\Discussion;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\Exceptions\UnknownContentType;
use InvalidArgumentException;
use Throwable;

/**
 * Every content type: code's, registered as the application boots, then the
 * site's own, stored in the database and read when first asked for (I-411),
 * with the stored taxonomies, which it loads first (I-414).
 * It refuses a type whose key, segment or abilities would break storage,
 * routing or authorization; a stored one it refuses is left out and its
 * reason kept, never thrown. Internal and unversioned until the plugin API
 * (phase 5).
 */
final class ContentTypeRegistry
{
    /**
     * First path segments under /admin that no listed type or taxonomy may
     * claim: the fixed admin screens, Fortify's pages, the builders' screens,
     * and room for later ones. A part that adds a fixed admin screen adds its
     * segment here; a test holds every fixed route's to this list.
     */
    public const array RESERVED = [
        'dashboard', 'media', 'users', 'settings', 'account', 'terms', 'content', 'preview', 'search', 'quick-draft', 'navigation', 'regions', 'comments', 'tools',
        'themes', 'plugins', 'ai', 'inbox', 'my-jobs', 'types', 'taxonomies',
        'login', 'logout', 'two-factor', 'two-factor-challenge', 'user', 'api', 'up', 'invitation', 'setup', 'register',
        'email', 'forgot-password', 'reset-password', '_catalogue',
    ];

    private const string KEY = '/^[a-z][a-z0-9_]{0,31}$/';

    private const string SEGMENT = '/^[a-z][a-z0-9-]{0,39}$/';

    /** @var array<string, ContentType> code's types, then the stored ones */
    private array $types = [];

    /** @var array<string, ContentType> code's, as declared, in registration order */
    private array $declared = [];

    /** @var array<string, string> a stored type's key => why it isn't registered */
    private array $refused = [];

    /** The version of the stored types registered now; null until they are read. */
    private ?string $storedVersion = null;

    /** Whether the next read asks the store again. */
    private bool $checkStored = true;

    /** Changes whenever the registered types do, so what is built from them can tell. */
    private string $stamp;

    public function __construct()
    {
        $this->stamp = bin2hex(random_bytes(8));
    }

    /**
     * The `{type}` admin routes' pattern for a segment: any a type could
     * have, except the admin's own (I-3296). Built from constants only, so
     * cached routes keep it and every type made later matches it.
     */
    public static function segmentPattern(): string
    {
        $reserved = implode('|', array_map(fn (string $segment): string => preg_quote($segment, '#'), self::RESERVED));

        return "(?!(?:{$reserved})(?![a-z0-9-]))[a-z][a-z0-9-]{0,39}";
    }

    public function register(ContentType $type): void
    {
        $problem = $this->problem($type);

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        $this->declared[$type->key] = $type;
        $this->types[$type->key] = $type;
        $this->stamp = bin2hex(random_bytes(8));
    }

    /**
     * What register() would refuse $type for, with the stored types read and
     * the type now under $replacing set aside; null when nothing.
     */
    public function refusal(ContentType $type, ?string $replacing = null): ?string
    {
        $this->loadStored();

        return $this->problem($type, $replacing);
    }

    /**
     * What $type names that nothing provides, a taxonomy nobody registered
     * or a feature no field gives; null when it holds together. Reads no
     * database, so boot may ask it.
     */
    public function unmet(ContentType $type): ?string
    {
        foreach ($type->taxonomies as $key) {
            if (! app(TaxonomyRegistry::class)->isRegistered($key)) {
                return "Content type [{$type->key}] uses taxonomy [{$key}], which is not registered.";
            }
        }

        $features = app(FieldRegistry::class)->features();

        foreach ($type->supports as $feature) {
            if (! in_array($feature, $features, true)) {
                return "Content type [{$type->key}] supports [{$feature}], which no field provides.";
            }
        }

        return null;
    }

    /**
     * The type registered under $key.
     */
    public function get(string $key): ContentType
    {
        $this->loadStored();

        return $this->types[$key] ?? throw UnknownContentType::named($key);
    }

    public function has(string $key): bool
    {
        $this->loadStored();

        return isset($this->types[$key]);
    }

    /**
     * The ability that governs $action on items of $typeKey: the type's own,
     * or the global default when the type isn't registered — so an unknown
     * type reaches validation, which refuses it, instead of throwing here.
     */
    public function ability(string $typeKey, string $action): string
    {
        if ($this->has($typeKey)) {
            return $this->types[$typeKey]->ability($action);
        }

        if (! array_key_exists($action, ContentType::ACTIONS)) {
            throw new InvalidArgumentException("[{$action}] is not a content action; see ContentType::ACTIONS.");
        }

        return ContentType::ACTIONS[$action];
    }

    /**
     * Every type, code's in registration order, then the stored ones, oldest first.
     *
     * @return list<ContentType>
     */
    public function all(): array
    {
        $this->loadStored();

        return array_values($this->types);
    }

    /**
     * The types code registered, in order, without reading the stored ones:
     * what boot may ask.
     *
     * @return list<ContentType>
     */
    public function fromCode(): array
    {
        return array_values($this->declared);
    }

    /**
     * The listed type whose admin list lives at /admin/{$segment}, or null.
     */
    public function bySegment(string $segment): ?ContentType
    {
        foreach ($this->all() as $type) {
            if ($type->listed && $type->segment === $segment) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Every type's key, in the order all() gives.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (ContentType $type): string => $type->key, $this->all());
    }

    /**
     * The segments of the listed types, the ones with an admin list screen.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        return array_values(array_map(
            fn (ContentType $type): string => $type->segment,
            array_filter($this->all(), fn (ContentType $type): bool => $type->listed),
        ));
    }

    /**
     * The stored types left out, each with why.
     *
     * @return array<string, string>
     */
    public function refused(): array
    {
        $this->loadStored();

        return $this->refused;
    }

    /**
     * Has the next read ask the store again: before a worker's next job,
     * after a request, and after a change to the types commits (I-3296).
     */
    public function refreshStored(): void
    {
        $this->checkStored = true;
    }

    /**
     * A value that changes whenever the registered types do.
     */
    public function stamp(): string
    {
        $this->loadStored();

        return $this->stamp;
    }

    /**
     * Reads the stored types and taxonomies when they have changed, for
     * TaxonomyRegistry's readers. Never called at boot.
     */
    public function readStored(): void
    {
        $this->loadStored();
    }

    /**
     * Registers the stored taxonomies and then the stored types, when the
     * store's version differs from the one registered: each must parse, pass
     * register()'s checks and name only what exists, or it is left out with
     * its reason.
     */
    private function loadStored(): void
    {
        if (! $this->checkStored) {
            return;
        }

        // Before reading: registering a stored type asks this registry again.
        $this->checkStored = false;

        try {
            $snapshot = app(StoredTypes::class)->snapshot();

            if ($snapshot['version'] === $this->storedVersion) {
                return;
            }

            $this->types = $this->declared;
            $this->refused = [];

            // Taxonomies first: a stored type may name one, and one may name a
            // type code declares (I-414).
            $listed = array_filter($this->declared, fn (ContentType $type): bool => $type->listed);
            $storedSegments = array_values(array_filter(array_map(fn (array $row): mixed => $row['segment'] ?? null, $snapshot['definitions']), 'is_string'));
            $taxonomies = app(TaxonomyRegistry::class)->replaceStored(
                $snapshot['taxonomies'],
                [...array_values(array_map(fn (ContentType $type): string => $type->segment, $listed)), ...$storedSegments],
                array_keys($listed),
            );

            foreach ($taxonomies as $taxonomy) {
                foreach ($taxonomy->codeTypes as $key) {
                    $this->types[$key] = $this->types[$key]->withTaxonomies([...$this->types[$key]->taxonomies, $taxonomy->key]);
                }
            }

            foreach ($snapshot['definitions'] as $definition) {
                $key = is_string($definition['key'] ?? null) ? $definition['key'] : '?';

                try {
                    $type = TypeDefinition::fromArray($definition)->toContentType();
                } catch (InvalidTypeDefinition $invalid) {
                    $this->refused[$key] = $invalid->getMessage();

                    continue;
                }

                $problem = $this->problem($type) ?? $this->unmet($type);

                if ($problem !== null) {
                    $this->refused[$key] = $problem;

                    continue;
                }

                $this->types[$type->key] = $type;
            }

            $this->storedVersion = $snapshot['version'];
            $this->stamp = bin2hex(random_bytes(8));
        } catch (Throwable $failure) {
            // So the next read tries again, rather than going on without them.
            $this->checkStored = true;

            throw $failure;
        }
    }

    /**
     * The checks register() makes, against what is registered now, the type
     * under $replacing set aside; null when $type passes.
     */
    private function problem(ContentType $type, ?string $replacing = null): ?string
    {
        if (preg_match(self::KEY, $type->key) !== 1) {
            return "Content type key [{$type->key}] must be lower case letters, digits and underscores, starting with a letter, at most 32 characters.";
        }

        if (isset($this->types[$type->key]) && $type->key !== $replacing) {
            return "Content type [{$type->key}] is already registered.";
        }

        if (preg_match(self::SEGMENT, $type->segment) !== 1) {
            return "Content type [{$type->key}] has segment [{$type->segment}]: a segment is lower case letters, digits and hyphens, starting with a letter, at most 40 characters.";
        }

        if ($type->listed && in_array($type->segment, self::RESERVED, true)) {
            return "Content type [{$type->key}] cannot use the reserved segment [{$type->segment}].";
        }

        foreach ($this->types as $existing) {
            if ($existing->key !== $replacing && $existing->segment === $type->segment && ($existing->listed || $type->listed)) {
                return "Content type [{$type->key}] cannot use segment [{$type->segment}]: content type [{$existing->key}] has it.";
            }
        }

        if ($type->listed && in_array($type->segment, app(TaxonomyRegistry::class)->registeredSegments(), true)) {
            return "Content type [{$type->key}] cannot use segment [{$type->segment}]: a taxonomy has it.";
        }

        foreach (array_keys($type->abilities) as $action) {
            if (! array_key_exists($action, ContentType::ACTIONS)) {
                return "Content type [{$type->key}] names an unknown action [{$action}] in its abilities.";
            }
        }

        // Comments are shown on an item's own page, which only a public
        // type's items have; and a new item starts Open or Closed, since on
        // an item with no comments yet Hidden looks the same as Closed (I-572).
        if ($type->comments !== null && ! $type->public) {
            return "Content type [{$type->key}] takes comments, but isn't public: comments are shown on an item's own page.";
        }

        if ($type->comments === Discussion::Hidden) {
            return "Content type [{$type->key}] starts its items Hidden: a type's new items start Open or Closed, and Hidden is chosen item by item.";
        }

        return null;
    }
}
