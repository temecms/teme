<?php

/**
 * The map from a card's name to its plain version in a feed (I-861), kept
 * here so no card class changes. A card with no entry, which only a plugin's
 * can be, gives its own words as a paragraph. Each part registers its
 * versions under RENDERERS from its provider's register().
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Cards\CardType;
use App\Domain\Cards\RenderContext;
use InvalidArgumentException;
use UnexpectedValueException;

final class FeedCards
{
    /** The container tag a card's plain version is registered under. */
    public const string RENDERERS = 'teme.feeds.cards';

    /** @var array<string, FeedCardRenderer> */
    private array $renderers = [];

    /**
     * @param  iterable<mixed>  $renderers
     */
    public function __construct(iterable $renderers)
    {
        foreach ($renderers as $renderer) {
            if (! $renderer instanceof FeedCardRenderer) {
                throw new UnexpectedValueException('Registered as a FeedCardRenderer, but '.get_debug_type($renderer).' isn\'t one.');
            }

            if (isset($this->renderers[$renderer->card()])) {
                throw new InvalidArgumentException("Two feed versions are written for the card [{$renderer->card()}].");
            }

            $this->renderers[$renderer->card()] = $renderer;
        }
    }

    /**
     * Whether this card has a version written for it.
     */
    public function has(string $name): bool
    {
        return isset($this->renderers[$name]);
    }

    /**
     * The card's HTML for a feed.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function html(CardType $card, array $attrs, RenderContext $context): string
    {
        $renderer = $this->renderers[$card->name()] ?? null;

        if ($renderer !== null) {
            return $renderer->html($attrs, $context);
        }

        // Later (I-920): a plugin's card gives its own feed version when the plugin API arrives.
        $words = trim($card->plainText($attrs));

        return $words === '' ? '' : '<p>'.e($words).'</p>';
    }
}
