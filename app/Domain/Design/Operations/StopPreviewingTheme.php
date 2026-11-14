<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\StopPreviewingThemeInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use Teme\Contracts\Operations\OperationInput;

/**
 * Stops previewing a theme on Site › Themes: the design draft's switch to it
 * goes, and with it the unpublished changes to that theme's own settings and
 * menu places. Every other waiting change stays, and nothing live changes.
 */
final class StopPreviewingTheme extends BaseOperation
{
    use ManagesDesign;

    public function __construct(private readonly ThemeRegistry $themes) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var StopPreviewingThemeInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    /**
     * @return string|null the theme's name, or null when the draft wasn't previewing it
     */
    protected function perform(OperationInput $input): ?string
    {
        /** @var StopPreviewingThemeInput $input */
        $design = SettingsGroupRecord::query()->lockForUpdate()->find(self::DESIGN_GROUP);
        $draft = $design->draft_values ?? [];

        // Someone may have stopped it, or previewed another theme, meanwhile.
        if ($design === null || ($draft['theme'] ?? null) !== $input->code) {
            return null;
        }

        unset($draft['theme']);
        $this->keep($design, $draft);

        $own = SettingsGroupRecord::query()->lockForUpdate()->find(ThemeSlotSettings::groupKey($input->code));

        if ($own !== null && $own->draft_values !== null) {
            $this->clearDraft($own);
        }

        return $this->themes->find($input->code)?->manifest?->name() ?? $input->code;
    }

    /**
     * Keeps what is left of the design's draft, or clears it when nothing is.
     *
     * @param  array<string, mixed>  $draft
     */
    private function keep(SettingsGroupRecord $record, array $draft): void
    {
        if ($draft === []) {
            $this->clearDraft($record);

            return;
        }

        $record->draft_values = $draft;
        // The live columns describe the live values, as SaveDesignDraft keeps them.
        $record->timestamps = false;
        $record->save();
    }
}
