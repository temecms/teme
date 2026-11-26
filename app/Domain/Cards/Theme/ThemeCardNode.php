<?php

/**
 * The one node type every theme's card is stored as in a region's
 * document: the card's key and the values of its fields.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Theme;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RegionCard;
use App\Domain\Cards\RenderContext;
use App\Domain\Settings\SettingRules;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cards\CardRegion;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * A theme's own card in one of its regions
 * (docs/guides/developers/themes/regions.md). Every theme card is this one
 * node type, its attrs naming the card as "{theme}:{id}", as a plugin's
 * card is, so a region kept from another theme still validates and opens.
 * The theme's view draws it, and only while that theme is active.
 */
final class ThemeCardNode implements CardType, RegionCard
{
    /** A card's key: the theme's code, a colon, the card's id. */
    public const string KEY = '/^[a-z0-9][a-z0-9-]{1,39}:[a-z][a-z0-9-]{1,40}$/';

    /** A card's id as a theme's manifest declares it. */
    public const string ID = '/^[a-z][a-z0-9-]{1,40}$/';

    /** The most bytes a card's values take while its theme isn't installed, kept as they are. */
    public const int MOST_KEPT_BYTES = 16_384;

    public function name(): string
    {
        return 'theme-card';
    }

    public function label(): string
    {
        return 'Theme card';
    }

    public function description(): string
    {
        return 'A card a theme brings for its own regions, filled in with the fields the theme declares and drawn by the theme.';
    }

    public function since(): string
    {
        return '1.5.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'card' => new CardAttribute('card', ['required', 'string', 'regex:'.self::KEY]),
            'values' => new CardAttribute('values', ['array', 'max:40'], []),
        ];
    }

    /**
     * Never in a menu itself: each theme's cards are entries of their own.
     */
    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Theme card', 'Cards', ['theme', 'widget'], 'palette', inMenus: false, regionOnly: true);
    }

    /**
     * What the theme's view draws, wrapped; nothing outside a web page,
     * while another theme is active, or when the view draws nothing. The
     * theme's view escapes the values it prints.
     */
    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $key = (string) ($attrs['card'] ?? '');
        $definition = app(ThemeCards::class)->find($key);

        if ($context->target !== 'web' || $definition === null || app(ActiveTheme::class)->get()?->code !== $definition->theme) {
            return '';
        }

        $view = 'theme::cards.own.'.$definition->id;

        if (! View::exists($view)) {
            return '';
        }

        $values = is_array($attrs['values'] ?? null) ? $attrs['values'] : [];
        $html = View::make($view, [
            'id' => (string) ($attrs['id'] ?? ''),
            'values' => $definition->withDefaults($values),
            'region' => $region = $context->region ?? new CardRegion('', $definition->theme),
            'regionInfo' => $region,
        ])->render();

        if (trim($html) === '') {
            return '';
        }

        return '<div class="card-theme card-theme-'.e($definition->id).'" data-theme-card="'.e($key).'">'.$html.'</div>';
    }

    /**
     * The theme's markup is drawn for pages only, never searched.
     */
    public function plainText(array $attrs): string
    {
        return '';
    }

    public function references(array $attrs): array
    {
        return [];
    }

    /**
     * An installed theme's card holds only its fields, each by its rules; a
     * card whose theme is gone is kept as it is, up to 16 KB.
     */
    public function validate(array $attrs): void
    {
        $values = is_array($attrs['values'] ?? null) ? $attrs['values'] : [];
        $definition = app(ThemeCards::class)->find((string) ($attrs['card'] ?? ''));

        if ($definition === null) {
            if (strlen((string) json_encode($values)) > self::MOST_KEPT_BYTES) {
                throw ValidationException::withMessages(['values' => 'This card holds more than its theme can be kept with.']);
            }

            return;
        }

        $group = new SettingsGroup(
            key: 'card.'.$definition->theme,
            label: $definition->label,
            description: $definition->description,
            section: 'themes',
            ability: 'regions.manage',
            settings: $definition->fields,
            since: '1.5.0',
        );

        try {
            SettingRules::validateValues($group, $values);
        } catch (ValidationException $refused) {
            $key = (string) array_key_first($refused->errors());
            $field = $definition->field(substr($key, strlen('values.')));
            $message = (string) ($refused->errors()[$key][0] ?? 'Invalid.');

            throw ValidationException::withMessages([$key => $field === null ? $message : "{$field->label}: {$message}"]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $definition = app(ThemeCards::class)->find((string) ($after['card'] ?? $before['card'] ?? ''));

        return ChangeList::line($definition->label ?? $this->label(), ChangeList::differs($before, $after, 'values') ? ['changed'] : []);
    }
}
