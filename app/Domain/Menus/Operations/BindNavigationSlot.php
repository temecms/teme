<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Chooses the menu a theme's slot shows, or none, to the default language's
 * readers or, given a language, to that language's (I-3360), who see the
 * default's where theirs has none. Someone who may manage navigation may
 * place their own menu, as may someone who manages the site's design. The
 * choice is a theme setting, written through SettingsWriter so the settings
 * history records it, and every page drawn in the design is purged after
 * commit.
 */
final class BindNavigationSlot extends BaseOperation
{
    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
        private readonly ThemeRegistry $themes,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var BindNavigationSlotInput $input */
        $actor = User::query()->find($input->actorId);

        if ($actor === null || ! ($actor->can('navigation.manage') || $actor->can('settings.design.manage'))) {
            throw new AuthorizationException('Not permitted to choose where menus are shown.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var BindNavigationSlotInput $input */
        $theme = $this->themes->find($input->themeCode);
        $slots = $theme !== null && $theme->usable() ? ($theme->manifest?->navigations() ?? []) : [];

        if (! array_key_exists($input->slot, $slots) || $this->registry->find(ThemeSlotSettings::groupKey($input->themeCode)) === null) {
            throw ValidationException::withMessages(['slot' => __('The active theme has no menu place called “:slot”. Reload the page and choose again.', ['slot' => $input->slot])]);
        }

        if ($input->language !== null && ! in_array($input->language, app(SiteLanguages::class)->others(), true)) {
            throw ValidationException::withMessages(['language' => __(":language isn't one of the site's other languages.", ['language' => app(SiteLanguages::class)->label($input->language)])]);
        }
    }

    protected function perform(OperationInput $input): ?string
    {
        /** @var BindNavigationSlotInput $input */
        // Locked, so a menu being deleted now can't be bound as it goes.
        if ($input->navigationId !== null && Navigation::query()->lockForUpdate()->find($input->navigationId) === null) {
            throw ValidationException::withMessages(['navigation' => __('There is no such menu.')]);
        }

        // Another language's menu is that language's word for the slot:
        // none given shows the default language's menu there.
        if ($input->language !== null) {
            $this->writer->writeTranslations(
                ThemeSlotSettings::groupKey($input->themeCode),
                $input->language,
                [ThemeSlotSettings::settingKey($input->slot) => $input->navigationId ?? ''],
                [],
                null,
                $input->actorId,
            );

            return $input->navigationId;
        }

        // origin human, as ChangeAddresses writes: settings_changes allows
        // only human, ai, import and system (B-204).
        $this->writer->write(new SettingsWrite(
            group: ThemeSlotSettings::groupKey($input->themeCode),
            values: [ThemeSlotSettings::settingKey($input->slot) => $input->navigationId ?? ''],
            actorId: $input->actorId,
            origin: 'human',
        ));

        return $input->navigationId;
    }
}
