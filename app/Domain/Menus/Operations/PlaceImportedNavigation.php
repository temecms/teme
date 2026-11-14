<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Input\PlaceImportedNavigationInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Shows an imported menu in a theme's place (I-343), as the import's
 * choices say, only where the place shows nothing yet: a place a person
 * filled is never changed. Written as BindNavigationSlot writes it, a
 * theme setting through SettingsWriter, its origin the import.
 */
final class PlaceImportedNavigation extends BaseOperation
{
    use AuthorizesImports;

    /** The place showed nothing, and shows the menu now. */
    public const string PLACED = 'placed';

    /** The place showed the menu already. */
    public const string UNCHANGED = 'unchanged';

    /** The place shows another menu, left as it is. */
    public const string TAKEN = 'taken';

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
        private readonly ThemeRegistry $themes,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PlaceImportedNavigationInput $input */
        $theme = $this->themes->find($input->themeCode);
        $slots = $theme !== null && $theme->usable() ? ($theme->manifest?->navigations() ?? []) : [];

        if (! array_key_exists($input->slot, $slots) || $this->registry->find(ThemeSlotSettings::groupKey($input->themeCode)) === null) {
            throw ValidationException::withMessages(['slot' => __('The active theme has no menu place called “:slot”. Reload the page and choose again.', ['slot' => $input->slot])]);
        }
    }

    /**
     * @return array{outcome: string, shown: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var PlaceImportedNavigationInput $input */
        if (Navigation::query()->lockForUpdate()->find($input->navigationId) === null) {
            throw ValidationException::withMessages(['navigation' => __('There is no such menu.')]);
        }

        $group = ThemeSlotSettings::groupKey($input->themeCode);
        $key = ThemeSlotSettings::settingKey($input->slot);
        // Read under the group's lock, never from the settings cache, so a person placing a menu now is seen.
        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $group, 'overrides' => '{}', 'version' => 0]);
        $overrides = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($group)->overrides;
        $shown = is_string($overrides[$key] ?? null) ? $overrides[$key] : '';

        if ($shown === $input->navigationId) {
            return ['outcome' => self::UNCHANGED, 'shown' => $shown];
        }

        // A place naming a menu since deleted shows nothing.
        if ($shown !== '' && Navigation::query()->whereKey($shown)->exists()) {
            return ['outcome' => self::TAKEN, 'shown' => $shown];
        }

        $this->writer->write(new SettingsWrite(
            group: $group,
            values: [$key => $input->navigationId],
            actorId: $input->actorId,
            origin: 'import',
        ));

        return ['outcome' => self::PLACED, 'shown' => $input->navigationId];
    }
}
