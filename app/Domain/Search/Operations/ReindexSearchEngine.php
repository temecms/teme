<?php

/**
 * Asks the chosen search engine to take every published item again
 * (docs/guides/developers/plugins/search-engine.md). A person who holds
 * plugins.manage, or the operator. Refused while no engine is chosen. The
 * sending is queued after commit.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search\Operations;

use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Domain\Search\Jobs\SyncSearchEntry;
use App\Domain\Search\Operations\Input\ReindexSearchEngineInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ReindexSearchEngine extends BaseOperation
{
    use AuthorizesPluginChanges;

    public function __construct(private readonly SlotProviders $providers) {}

    protected function validate(OperationInput $input): void
    {
        /** @var ReindexSearchEngineInput $input */
        if ($this->providers->chosenFor(PluginSlots::SEARCH) === null) {
            throw ValidationException::withMessages(['slot' => [__('No search engine is chosen.')]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        return PluginSlots::SEARCH;
    }

    protected function afterCommit(mixed $result): void
    {
        SyncSearchEntry::dispatch(null, SyncSearchEntry::REINDEX);
    }
}
