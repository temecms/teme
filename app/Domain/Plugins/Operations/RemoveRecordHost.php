<?php

/**
 * Removes a host a person agreed to for a plugin's records, for someone who
 * manages plugins; no password, since removing only narrows what the plugin
 * reaches. The records that named the host show the waiting notice again.
 * Returns the hosts agreed for records afterwards.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\RemoveRecordHostInput;
use App\Domain\Plugins\PluginSentences;
use App\Domain\Plugins\RecordHosts;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Removes a host agreed for a plugin's records.
 *
 * @since next
 */
final class RemoveRecordHost extends BaseOperation
{
    use AuthorizesPluginChanges;

    /**
     * Refuses a host that is not agreed for records, writing nothing.
     *
     * @since next
     */
    protected function validate(OperationInput $input): void
    {
        /** @var RemoveRecordHostInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->first();

        if ($record === null || ! in_array($input->host, RecordHosts::agreed($record->consent), true)) {
            throw ValidationException::withMessages(['host' => [app(PluginSentences::class)->coreOnly(fn (): string => __(':host isn’t agreed for this plugin’s records.', ['host' => $input->host]))]]);
        }
    }

    /**
     * @return list<string>
     *
     * @since next
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RemoveRecordHostInput $input */
        return RecordHosts::remove($input->name, $input->host);
    }
}
