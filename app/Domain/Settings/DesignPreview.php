<?php

/**
 * Whether this request previews Settings › Design's unpublished changes, and
 * the drafts it shows, which SettingsRepository lays over the live values of
 * the `design` and `theme.{code}` groups. Scoped to one request, and set on
 * every public request by DesignPreviewLink, active or not.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Database\Eloquent\Builder;

final class DesignPreview
{
    private const string DESIGN_GROUP = 'design';

    private const string THEME_PREFIX = 'theme.';

    private bool $active = false;

    /** @var array<string, array<string, mixed>> */
    private array $drafts = [];

    /**
     * Whether a settings group is part of the design, so a preview shows its draft.
     */
    public static function covers(string $group): bool
    {
        return $group === self::DESIGN_GROUP || str_starts_with($group, self::THEME_PREFIX);
    }

    /**
     * Turns the preview on, loading every draft in one query, or off, and
     * says whether that changed it.
     */
    public function set(bool $active): bool
    {
        $changed = $active !== $this->active;
        $this->active = $active;
        $this->drafts = $active ? $this->load() : [];

        return $changed;
    }

    /**
     * Whether this request shows the drafts.
     */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * A group's draft values, key => value, or [] when it has none or no
     * preview is active.
     *
     * @return array<string, mixed>
     */
    public function draft(string $group): array
    {
        return $this->drafts[$group] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $drafts = [];

        $records = SettingsGroupRecord::query()
            ->whereNotNull('draft_values')
            ->where(fn (Builder $query) => $query->where('group_key', self::DESIGN_GROUP)->orWhere('group_key', 'like', self::THEME_PREFIX.'%'))
            ->get(['group_key', 'draft_values']);

        foreach ($records as $record) {
            $drafts[$record->group_key] = $record->draft_values ?? [];
        }

        return $drafts;
    }
}
