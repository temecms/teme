<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations\Concerns;

use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Teme\Contracts\Operations\OperationInput;

/**
 * What the design Operations share: who may change the site's design, which
 * settings groups are part of it, and how a group's draft is taken and
 * cleared.
 */
trait ManagesDesign
{
    private const string DESIGN_GROUP = 'design';

    private function authorizeDesign(OperationInput $input): void
    {
        $actorId = $input->actorId();
        $actor = $actorId === null ? null : User::query()->find($actorId);

        if ($actor === null || ! $actor->can('settings.design.manage')) {
            throw new AuthorizationException('Not permitted to change the site\'s design.');
        }
    }

    /**
     * Whether a group is part of Settings › Design: the site's `design`
     * group, or a theme's own.
     */
    private function isDesignGroup(string $group): bool
    {
        return $group === self::DESIGN_GROUP || str_starts_with($group, ThemeSlotSettings::GROUP_PREFIX);
    }

    /**
     * Every design and theme row holding a draft, locked before it is read.
     *
     * @return Collection<int, SettingsGroupRecord>
     */
    private function lockedDrafts(): Collection
    {
        return SettingsGroupRecord::query()
            ->whereNotNull('draft_values')
            ->where(fn (Builder $query) => $query->where('group_key', self::DESIGN_GROUP)->orWhere('group_key', 'like', ThemeSlotSettings::GROUP_PREFIX.'%'))
            ->orderBy('group_key')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Clears a row's draft. A row that exists only for its draft, version 0,
     * goes, as SettingsWriter deletes a group it made for nothing. The live
     * columns, `updated_at` among them, are left alone.
     */
    private function clearDraft(SettingsGroupRecord $record): void
    {
        if ($record->version === 0) {
            $record->delete();

            return;
        }

        $record->draft_values = null;
        $record->draft_updated_by = null;
        $record->draft_updated_at = null;
        $record->timestamps = false;
        $record->save();
    }
}
