<?php

/**
 * Switches an entry of site code on or off, from its row: carrying no code,
 * it can't overwrite an edit saved meanwhile. Elevated (I-864), logged, and
 * every stored page purged when pages draw it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\Concerns\ChangesSiteCode;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class SwitchSiteCode extends BaseOperation implements RequiresElevation
{
    use ChangesSiteCode;

    protected function validate(OperationInput $input): void
    {
        // The entry is found under its lock, in perform().
    }

    /**
     * @return array{name: string, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SwitchSiteCodeInput $input */
        $entry = SiteCodeEntry::query()->lockForUpdate()->find($input->entryId)
            ?? throw ValidationException::withMessages(['entry' => 'This code was deleted while you had it open.']);

        if ($entry->enabled === $input->on) {
            return ['name' => $entry->name, 'changed' => false];
        }

        $before = $entry->snapshot();
        $entry->enabled = $input->on;
        $entry->updated_by = (int) $input->actorId;
        $entry->save();
        $after = $entry->snapshot();

        $this->logChange($input->on ? SecurityEventType::SiteCodeSwitchedOn : SecurityEventType::SiteCodeSwitchedOff, $input, $entry->id, $before, $after);
        $this->purgeIfDrawn($before, $after);

        return ['name' => $entry->name, 'changed' => true];
    }
}
