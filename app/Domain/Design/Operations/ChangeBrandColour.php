<?php

/**
 * Changes the site's brand colour, the design group's accent, from Settings ›
 * General: live at once, never as a draft. It must pass contrast against the
 * light background of the live theme and of a theme being previewed on Site ›
 * Themes (I-457); a colour waiting there goes, so publishing can't bring it back.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Contrast;
use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsGroup;

final class ChangeBrandColour extends BaseOperation
{
    use ManagesDesign;

    /** The setting's key in the design group. */
    private const string KEY = 'accent';

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly ThemeRegistry $themes,
        private readonly SettingsWriter $writer,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeBrandColourInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeBrandColourInput $input */
        $setting = $this->group()->setting(self::KEY) ?? throw new LogicException('The design group has no brand colour.');
        $validator = Validator::make([self::KEY => $input->colour], [self::KEY => SettingRules::for($setting)], attributes: [self::KEY => 'brand colour']);

        if ($validator->fails()) {
            throw ValidationException::withMessages([self::KEY => $validator->errors()->first(self::KEY)]);
        }
    }

    /**
     * @return int the design group's version after the change
     */
    protected function perform(OperationInput $input): int
    {
        /** @var ChangeBrandColourInput $input */
        $colour = strtolower(trim($input->colour));
        // Made if missing and locked, as SaveDesignDraft does, so a theme
        // drafted meanwhile is checked too. A row this makes for nothing,
        // version 0 with no draft, the writer deletes again.
        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => self::DESIGN_GROUP, 'overrides' => '{}', 'version' => 0]);
        $record = SettingsGroupRecord::query()->lockForUpdate()->findOrFail(self::DESIGN_GROUP);

        if ($colour !== '') {
            $this->checkContrast($colour, $record);
        }

        if (array_key_exists(self::KEY, $record->draft_values ?? [])) {
            $this->dropDraftedColour($record);
        }

        return $this->writer->write(new SettingsWrite(
            group: self::DESIGN_GROUP,
            values: [self::KEY => $colour],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));
    }

    /**
     * The colour against the light background of the live theme, then of
     * the theme being previewed on Site › Themes, if another; a theme that
     * can't be used gives way to core's own background (Contrast::bases()).
     */
    private function checkContrast(string $colour, SettingsGroupRecord $record): void
    {
        $live = (string) ($record->overrides['theme'] ?? $this->group()->setting('theme')->default ?? '');
        $drafted = $record->draft_values['theme'] ?? null;
        $codes = is_string($drafted) && $drafted !== $live ? [$live, $drafted] : [$live];

        foreach ($codes as $position => $code) {
            $theme = $this->themes->find($code);
            $usable = $theme !== null && $theme->usable() ? $theme : null;
            $problems = Contrast::accentProblems($colour, Contrast::bases($usable?->manifest)['light']);

            if ($problems === []) {
                continue;
            }

            if ($position > 0) {
                array_unshift($problems, sprintf('With %s, being previewed in Themes:', $usable?->manifest?->name() ?? $code));
            }

            throw ValidationException::withMessages([self::KEY => implode(' ', $problems)]);
        }
    }

    /**
     * Takes the colour out of Design's draft, and clears the draft when it
     * held nothing else.
     */
    private function dropDraftedColour(SettingsGroupRecord $record): void
    {
        $draft = $record->draft_values ?? [];
        unset($draft[self::KEY]);

        if ($draft === []) {
            $this->clearDraft($record);

            return;
        }

        $record->draft_values = $draft;
        // The live columns describe the live values, as SaveDesignDraft keeps them.
        $record->timestamps = false;
        $record->save();
    }

    private function group(): SettingsGroup
    {
        return $this->registry->find(self::DESIGN_GROUP) ?? throw new LogicException('No design settings group is registered.');
    }
}
