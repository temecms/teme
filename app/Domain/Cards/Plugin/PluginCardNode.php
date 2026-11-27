<?php

/**
 * The one node type every plugin's card is stored as in a region's
 * document: the card's key and the values of its fields.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Plugin;

use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RegionCard;
use App\Domain\Cards\RenderContext;
use App\Domain\Languages\PageLanguage;
use App\Domain\Plugins\Styles\PluginStyleUsage;
use App\Domain\Settings\SettingRules;
use App\Domain\Site\Insertions\PluginMarkup;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cards\CardInstance;
use Teme\Contracts\Cards\CardRegion;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * A plugin's card in a theme's region (docs/guides/developers/plugins/cards.md).
 * Every plugin card is this one node type, its attrs naming the card as
 * "{code}:{id}", so a document keeps it, and still validates, while its
 * plugin is disabled: then it draws nothing, and comes back when the plugin
 * does. The plugin draws it; core cleans what it gives and wraps it.
 */
final class PluginCardNode implements CardType, RegionCard
{
    /** A card's key: the plugin's code, a colon, the card's id. */
    public const string KEY = '/^[a-z0-9][a-z0-9-]{1,39}:[a-z][a-z0-9-]{1,40}$/';

    /** The most bytes a card's values take while its plugin isn't running, kept as they are. */
    public const int MOST_KEPT_BYTES = 16_384;

    public function name(): string
    {
        return 'plugin-card';
    }

    public function label(): string
    {
        return 'Plugin card';
    }

    public function description(): string
    {
        return 'A card a plugin offers for a theme’s regions, filled in with the fields the plugin declares and drawn by the plugin.';
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
     * Never in a menu itself: each plugin's cards are entries of their own.
     */
    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Plugin card', 'Cards', ['plugin', 'widget'], 'plug', inMenus: false, regionOnly: true);
    }

    /**
     * What the plugin draws, cleaned and wrapped; nothing outside a web
     * page, while the plugin isn't running, or when it draws nothing. The
     * page carries the plugin's tag either way, so enabling it, or saving
     * its settings, purges the page.
     */
    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $key = (string) ($attrs['card'] ?? '');
        $code = strstr($key, ':', true);

        if ($code === false) {
            return '';
        }

        app(RenderRecord::class)->tag('plugin:'.$code);

        if ($context->target !== 'web') {
            return '';
        }

        $cards = app(PluginCards::class);
        $definition = $cards->find($key);

        if ($definition === null) {
            return '';
        }

        $values = is_array($attrs['values'] ?? null) ? $attrs['values'] : [];
        $instance = new CardInstance((string) ($attrs['id'] ?? ''), $values, $context->region ?? new CardRegion('', ''), app(PageLanguage::class)->tag());
        $html = app(PluginMarkup::class)->clean($code, $cards->draw($definition, $instance));

        if (trim($html) !== '') {
            app(PluginStyleUsage::class)->record($code, 'card:'.$definition->id);
        }

        if (trim($html) === '') {
            return '';
        }

        return '<div class="card-plugin card-plugin-'.e($code.'-'.$definition->id).'" data-plugin-card="'.e($key).'">'.$html.'</div>';
    }

    /**
     * The plugin's markup is drawn for pages only, never searched.
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
     * A running plugin's card holds only its fields, each by its rules; a
     * stopped plugin's is kept as it is, up to 16 KB.
     */
    public function validate(array $attrs): void
    {
        $values = is_array($attrs['values'] ?? null) ? $attrs['values'] : [];
        $definition = app(PluginCards::class)->find((string) ($attrs['card'] ?? ''));

        if ($definition === null) {
            if (strlen((string) json_encode($values)) > self::MOST_KEPT_BYTES) {
                throw ValidationException::withMessages(['values' => 'This card holds more than its plugin can be kept with.']);
            }

            return;
        }

        $group = new SettingsGroup(
            key: 'card.'.$definition->code,
            label: $definition->label,
            description: $definition->description,
            section: 'plugins',
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
        $definition = app(PluginCards::class)->find((string) ($after['card'] ?? $before['card'] ?? ''));

        return ChangeList::line($definition->label ?? $this->label(), ChangeList::differs($before, $after, 'values') ? ['changed'] : []);
    }
}
