<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\FediverseSettingsGroup;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What the site sends to the fediverse: which content types, as which
 * object type, and which of its actors speak for an item. Read from the
 * `fediverse` settings group, with the defaults here for what it does not
 * hold yet; who speaks follows Settings › Fediverse's "Who can be followed".
 */
final readonly class PublishingSettings
{
    public const string GROUP = 'fediverse';

    /** The blog actor alone sends every item. */
    public const string MODE_BLOG = 'blog';

    /** Each item's author actor sends it, or the blog actor when it has none. */
    public const string MODE_ACTOR = 'actor';

    /** The author actor sends it and the blog actor announces it. */
    public const string MODE_ACTOR_BLOG = 'actor_blog';

    public const array MODES = [self::MODE_BLOG, self::MODE_ACTOR, self::MODE_ACTOR_BLOG];

    public const array OBJECT_TYPES = ['Article', 'Note'];

    public function __construct(private SettingsReader $settings) {}

    /**
     * The content types whose items are federated.
     *
     * @return list<string>
     */
    public function types(): array
    {
        try {
            return $this->settings->stringList(self::GROUP, 'types');
        } catch (InvalidArgumentException) {
            return ['post'];
        }
    }

    /**
     * Whether items of this type are federated.
     */
    public function federates(string $type): bool
    {
        return in_array($type, $this->types(), true);
    }

    /**
     * The object type an item is sent as: Article or Note.
     */
    public function objectType(): string
    {
        try {
            $type = $this->settings->string(self::GROUP, 'object_type');
        } catch (InvalidArgumentException) {
            return 'Article';
        }

        return in_array($type, self::OBJECT_TYPES, true) ? $type : 'Article';
    }

    /**
     * Which actors speak for an item: blog, actor or actor_blog, from who
     * can be followed (the blog, each author, or both).
     */
    public function mode(): string
    {
        try {
            $followable = FediverseSettingsGroup::followable();
        } catch (InvalidArgumentException) {
            return self::MODE_BLOG;
        }

        return match ($followable) {
            FediverseSettingsGroup::AUTHORS => self::MODE_ACTOR,
            FediverseSettingsGroup::BOTH => self::MODE_ACTOR_BLOG,
            default => self::MODE_BLOG,
        };
    }
}
