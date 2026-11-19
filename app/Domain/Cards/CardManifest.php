<?php

/**
 * The cards, embed providers and image sizes as the editor in the browser
 * needs them. It exists so that the browser never carries a second copy of
 * a card's definition: each card's name, label, menu entry, attributes and
 * defaults come from its CardType on the server, and the editor reads them
 * from here.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Themes\ActiveTheme;

final class CardManifest
{
    /**
     * @return array{
     *     cards: list<array{name: string, label: string, description: string, menuLabel: string, group: string, keywords: list<string>, icon: string, inMenus: bool, regionOnly: bool, attributes: list<array{name: string, default: mixed, options: list<string>|null}>}>,
     *     embedProviders: list<array{name: string, match: string, frame: string, kind: string}>,
     *     imageSizes: array<string, string>,
     * }
     */
    public static function forBrowser(CardRegistry $registry): array
    {
        $cards = [];

        foreach ($registry->all() as $card) {
            $menu = $card->menu();
            $attributes = [];

            foreach ($card->attributes() as $name => $attribute) {
                $attributes[] = ['name' => $name, 'default' => $attribute->default, 'options' => self::options($attribute)];
            }

            $cards[] = [
                'name' => $card->name(),
                'label' => $card->label(),
                'description' => $card->description(),
                'menuLabel' => $menu->label,
                'group' => $menu->group,
                'keywords' => $menu->keywords,
                'icon' => $menu->icon,
                'inMenus' => $menu->inMenus,
                'regionOnly' => $menu->regionOnly,
                'attributes' => $attributes,
            ];
        }

        $providers = [];

        // The patterns carry PHP's ~ delimiters; the browser builds them with
        // new RegExp(), and they use nothing JavaScript's patterns lack.
        foreach ((array) config('embeds.providers', []) as $name => $provider) {
            $providers[] = [
                'name' => (string) $name,
                'match' => substr((string) $provider['match'], 1, -1),
                'frame' => (string) $provider['frame'],
                'kind' => (string) ($provider['kind'] ?? 'link'),
            ];
        }

        // The active theme's image contexts, so the editor's images carry
        // the sizes the site's do (I-245).
        $imageSizes = app(ActiveTheme::class)->get()?->manifest?->images() ?? [];

        return ['cards' => $cards, 'embedProviders' => $providers, 'imageSizes' => $imageSizes];
    }

    /**
     * The values an attribute's `in:` rule allows, for the card views to
     * offer as a choice, or null when it has no such rule.
     *
     * @return list<string>|null
     */
    private static function options(CardAttribute $attribute): ?array
    {
        foreach ($attribute->rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3));
            }
        }

        return null;
    }
}
