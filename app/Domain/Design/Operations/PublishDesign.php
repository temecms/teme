<?php

/**
 * Publishes every design draft at once, through SettingsWriter, so the
 * settings history records it. Only the keys a draft holds are written, so
 * every other setting keeps its live value, including a menu placed on
 * Content › Navigation after the draft was saved. Either everything
 * publishes or nothing does, and every page drawn in the design is purged
 * after commit, through SettingsWriter.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class PublishDesign extends BaseOperation
{
    use ManagesDesign;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsWriter $writer,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var PublishDesignInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    /**
     * @return list<string> the groups whose drafts were published
     */
    protected function perform(OperationInput $input): array
    {
        /** @var PublishDesignInput $input */
        $records = $this->lockedDrafts();
        $published = [];
        $problems = [];

        foreach ($records as $record) {
            $definition = $this->registry->find($record->group_key);

            foreach ($record->draft_values ?? [] as $key => $value) {
                $setting = $definition?->setting($key);

                // Checked again under lock, as BindNavigationSlot checks: a
                // menu deleted since the draft was saved can't be published.
                if ($setting !== null && str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX) && $value !== '' && Navigation::query()->lockForUpdate()->find($value) === null) {
                    $problems["groups.{$record->group_key}.{$key}"] = __('The menu chosen for :slot was deleted. Choose another.', ['slot' => $setting->label]);
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        foreach ($records as $record) {
            $group = $record->group_key;
            $definition = $this->registry->find($group);

            // A theme removed or updated since the draft was saved may have
            // taken groups or keys away: those go with the draft.
            $values = $definition === null ? [] : array_filter(
                $record->draft_values ?? [],
                fn (string $key): bool => $definition->setting($key) !== null,
                ARRAY_FILTER_USE_KEY,
            );

            // Cleared first, so a draft that changes nothing leaves no empty
            // version 0 row behind: the writer deletes one.
            $this->clearDraft($record);

            if ($values !== []) {
                $this->writer->write(new SettingsWrite(group: $group, values: $values, actorId: $input->actorId));
                $published[] = $group;
            }
        }

        return $published;
    }
}
