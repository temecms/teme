<?php

/**
 * Chooses the enabled plugin that fills a provider slot, or clears the choice
 * so core's default stands (docs/guides/developers/plugins/provider-slots.md).
 * A person who holds plugins.manage, or the operator. Refused for a slot core
 * doesn't declare or can't be filled yet, and for a plugin that isn't enabled
 * or doesn't provide the slot. Records the choice under lock; the pages that
 * drew the slot's output are purged after commit. Returns the slot.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\ChooseSlotProviderInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Domain\Search\PostgresSiteSearch;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class ChooseSlotProvider extends BaseOperation
{
    use AuthorizesPluginChanges;

    public function __construct(private readonly PluginRegistry $registry) {}

    protected function validate(OperationInput $input): void
    {
        /** @var ChooseSlotProviderInput $input */
        if (! PluginSlots::fillable($input->slot)) {
            throw ValidationException::withMessages(['slot' => [__('Teme has no provider slot named ":slot".', ['slot' => $input->slot])]]);
        }

        if ($input->plugin === null) {
            return;
        }

        $record = PluginRecord::query()->where('name', $input->plugin)->first();
        $manifest = $this->registry->find($input->plugin)?->manifest;

        if ($record === null || $record->state !== PluginRecord::ENABLED || $manifest === null || ! SlotProviders::provides($manifest, $input->slot)) {
            throw ValidationException::withMessages(['plugin' => [__('That plugin is not enabled and providing this slot.')]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var ChooseSlotProviderInput $input */
        // Locked before the read, so two choices can't both see the other's absence.
        $previous = DB::table('plugin_slot_choices')->where('slot', $input->slot)->lockForUpdate()->value('plugin');

        if ($input->plugin === null) {
            DB::table('plugin_slot_choices')->where('slot', $input->slot)->delete();
        } else {
            DB::table('plugin_slot_choices')->upsert([
                ['slot' => $input->slot, 'plugin' => $input->plugin, 'chosen_by' => $input->actorId === null ? null : (int) $input->actorId, 'chosen_at' => CarbonImmutable::now(), 'notice' => null],
            ], ['slot'], ['plugin', 'chosen_by', 'chosen_at', 'notice']);
        }

        // Images drawn by either provider change address, so the pages that drew them go (docs/guides/developers/plugins/image-service.md).
        $codes = collect([$previous, $input->plugin])
            ->filter(fn (?string $name): bool => is_string($name) && $name !== '')
            ->map(fn (string $name): ?string => $this->registry->find($name)?->code)
            ->filter()
            ->map(fn (string $code): string => 'plugin:'.$code)
            ->values()
            ->all();

        // Searches drawn by either engine, or by core while none was chosen, change with the engine: the lists go.
        // A comment form's field follows the chosen check, so the pages with a comment form go (docs/guides/developers/plugins/comment-check.md).
        if ($input->slot === PluginSlots::COMMENT_CHECK) {
            $codes[] = PageTags::DISCUSSION;
        }

        if ($input->slot === PluginSlots::SEARCH) {
            $codes = [...$codes, ...array_map(PageTags::listOf(...), app(PostgresSiteSearch::class)->searchable(null))];
        }

        if ($codes !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'slot:'.$input->slot, $codes));
        }

        return $input->slot;
    }
}
