<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsGroup;
use UnexpectedValueException;

/**
 * Settings › AI, the `ai` group (I-315): one group, one row and one ability,
 * built from every section registered under SECTIONS, in the sections' own
 * order. It asks for the password again: a key spends the site's money, and a
 * provider decides where drafts are sent. No public page reads it.
 */
final readonly class AiSettings
{
    public const string KEY = 'ai';

    /** The container tag a section is registered under, from its part's own provider's register(). */
    public const string SECTIONS = 'teme.ai.sections';

    /** Administrators' (I-312). */
    public const string ABILITY = 'settings.ai.manage';

    private const string DESCRIPTION = 'The AI services Teme may use, the model each task uses, and how much people may ask.';

    private const string SWITCHED_OFF = ' AI is switched off on this server (TEME_AI=off), so none of this takes effect until it’s switched on.';

    public function __construct(private Container $container, private AiAvailability $availability) {}

    /**
     * The sections, lowest order first, each key checked, and no order twice.
     *
     * @return list<AiSettingsSection>
     */
    public function ordered(): array
    {
        $sections = [];
        $seen = [];
        $orders = [];

        foreach ($this->container->tagged(self::SECTIONS) as $section) {
            if (! $section instanceof AiSettingsSection) {
                throw new UnexpectedValueException('Registered as an AI settings section, but '.get_debug_type($section).' isn\'t one.');
            }

            $key = $section->key();
            $order = $section->order();
            AiKeys::check('AI settings section', $key, $seen);

            if (isset($orders[$order])) {
                throw new InvalidArgumentException("AI settings sections [{$orders[$order]}] and [{$key}] share the order {$order}.");
            }

            $orders[$order] = $key;
            $sections[] = $section;
        }

        usort($sections, fn (AiSettingsSection $one, AiSettingsSection $other): int => $one->order() <=> $other->order());

        return $sections;
    }

    /**
     * The group, its settings section by section. While the server has AI
     * switched off, its description says so, which is how the generic
     * settings form shows it (I-315).
     */
    public function group(): SettingsGroup
    {
        $settings = [];

        foreach ($this->ordered() as $section) {
            foreach ($section->settings() as $setting) {
                $settings[] = $setting;
            }
        }

        return new SettingsGroup(
            key: self::KEY,
            label: 'AI',
            description: self::DESCRIPTION.($this->availability->enabled() ? '' : self::SWITCHED_OFF),
            section: self::KEY,
            ability: self::ABILITY,
            settings: $settings,
            since: '0.1.0',
            sensitive: true,
        );
    }
}
