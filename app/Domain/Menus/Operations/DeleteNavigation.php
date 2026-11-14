<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Deletes a menu and its items, and clears every theme slot that showed it,
 * in every installed theme's settings, so no slot points at nothing.
 */
final class DeleteNavigation extends BaseOperation
{
    use ManagesNavigation;

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly ThemeSlotSettings $slots,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var DeleteNavigationInput $input */
        $this->authorizeNavigation($input);
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): Navigation
    {
        /** @var DeleteNavigationInput $input */
        $navigation = Navigation::query()->lockForUpdate()->findOrFail($input->id);

        // The same write BindNavigationSlot makes, so the settings history shows it.
        foreach ($this->slots->showing($navigation->id) as $group => $keys) {
            $this->writer->write(new SettingsWrite(group: $group, values: array_fill_keys($keys, ''), actorId: $input->actorId, origin: 'human'));
        }

        $navigation->delete();

        // Every page that drew it; SettingsWriter above purges the design's
        // when it was in a place (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MENU, (string) $navigation->handle, [PageTags::menu((string) $navigation->handle)]));

        return $navigation;
    }
}
